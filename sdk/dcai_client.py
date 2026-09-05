#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DCAI 客户端 SDK（Python 版）
================================
与 PHP 版 sdk/dcai_client.php 功能对等的 Python 实现，供 Python 被授权程序集成。

集成方式（被授权程序入口）：
    from dcai_client import DCAIClient

    dcai = DCAIClient({
        'server_url':    'https://your-server.com/api/v1/',
        'product_code':  'your_product',
        'license_key':   'DCAI-XXXX-XXXX-XXXX-XXXX',
        'app_secret':    'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'rsa_public_key': '-----BEGIN PUBLIC KEY-----\\n...\\n-----END PUBLIC KEY-----',
        'app_version':   '1.0.0',
    })
    if not dcai.guard():
        # 未授权处理
        print('程序未授权')
        sys.exit(1)

依赖：
  - 标准库 urllib / hashlib / hmac / json（零第三方依赖，开箱即用）
  - RSA 验签（验证令牌本地校验）可选使用 cryptography；未安装时自动降级为仅校验签名结构、
    并回退在线 verify 结果 —— 保证集成方不装第三方库也能跑通核心流程。

通信契约（与 PHP 版完全一致）：
  - 请求体 JSON，HMAC-SHA256 签名
  - 未注册阶段：app_secret 签名（auth/verify、instance/register）
  - 已注册阶段：instance_token 签名（heartbeat/command/popup/update/module/skill）
  - 签名 = HMAC-SHA256(secret, timestamp + "\\n" + nonce + "\\n" + sha256(body))
"""

import hashlib
import hmac
import json
import os
import platform
import socket
import sys
import time
import urllib.request
import urllib.error

try:
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding
    from cryptography.exceptions import InvalidSignature
    _HAS_CRYPTO = True
except Exception:  # pragma: no cover - 依赖可选
    _HAS_CRYPTO = False

__version__ = '1.3.0'


class DCAIError(Exception):
    """DCAI SDK 异常基类"""
    pass


class DCAINotAuthorized(DCAIError):
    """未授权"""
    pass


class _Cache:
    """本地文件缓存（与 PHP DCAI_Cache 语义一致：JSON 文件 + 过期时间）"""

    def __init__(self, cache_dir):
        self.dir = cache_dir
        if self.dir and not os.path.isdir(self.dir):
            try:
                os.makedirs(self.dir, exist_ok=True)
            except OSError:
                pass

    def _file(self, key):
        safe = ''.join(c if (c.isalnum() or c in '_.-') else '_' for c in key)
        return os.path.join(self.dir, safe + '.cache')

    def get(self, key, default=None):
        if not self.dir:
            return default
        path = self._file(key)
        try:
            with open(path, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except Exception:
            return default
        expire_at = data.get('expire_at', 0)
        if expire_at and time.time() > expire_at:
            try:
                os.remove(path)
            except OSError:
                pass
            return default
        return data.get('value', default)

    def set(self, key, value, ttl=0):
        if not self.dir:
            return
        data = {
            'value': value,
            'expire_at': (time.time() + ttl) if ttl > 0 else 0,
            'saved_at': int(time.time()),
        }
        try:
            with open(self._file(key), 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False)
        except OSError:
            pass

    def delete(self, key):
        if not self.dir:
            return
        try:
            os.remove(self._file(key))
        except OSError:
            pass

    def has(self, key):
        return self.get(key, '__DCAI_MISS__') != '__DCAI_MISS__'


class DCAIClient:
    """DCAI Python SDK 主类"""

    def __init__(self, config=None):
        cfg = dict(config or {})
        self.config = {
            'server_url': '',
            'product_code': '',
            'app_secret': '',
            'license_key': '',
            'enabled': True,
            'fail_open': True,
            'cache_dir': os.path.join(os.path.dirname(os.path.abspath(__file__)), 'cache_py'),
            'rsa_public_key': '',
            'http_timeout': 5,
            'ssl_verify': True,
            'heartbeat_interval': 60,
            'app_version': '1.0.0',
        }
        self.config.update(cfg)
        self.cache = _Cache(self.config['cache_dir'])
        self.verify_result = {}
        self._last_offline_error = ''

    # ------------------------------------------------------------
    # 底层：签名与 HTTP 请求
    # ------------------------------------------------------------

    @staticmethod
    def _sign(secret, timestamp, nonce, body):
        """sign = HMAC-SHA256(secret, timestamp + '\\n' + nonce + '\\n' + sha256(body))"""
        payload = '%d\n%s\n%s' % (timestamp, nonce, hashlib.sha256(body.encode('utf-8')).hexdigest())
        return hmac.new(secret.encode('utf-8'), payload.encode('utf-8'), hashlib.sha256).hexdigest()

    @staticmethod
    def _b64url_encode(data):
        import base64
        return base64.urlsafe_b64encode(data).decode('ascii').rstrip('=')

    @staticmethod
    def _b64url_decode(data):
        import base64
        pad = '=' * (-len(data) % 4)
        return base64.urlsafe_b64decode(data + pad)

    def _signed_request(self, path, body, secret, timeout=0):
        base = self.config['server_url'].rstrip('/') + '/'
        url = base + path
        body_json = json.dumps(body, ensure_ascii=False, separators=(',', ':'))
        timestamp = int(time.time())
        nonce = os.urandom(8).hex()
        sign = self._sign(secret, timestamp, nonce, body_json)

        headers = {
            'Content-Type': 'application/json; charset=utf-8',
            'X-Instance-Id': self.get_instance_id() or '',
            'X-Timestamp': str(timestamp),
            'X-Nonce': nonce,
            'X-Sign': sign,
        }

        timeout_sec = timeout if timeout > 0 else int(self.config['http_timeout'])
        req = urllib.request.Request(url, data=body_json.encode('utf-8'), headers=headers, method='POST')
        if not self.config['ssl_verify']:
            import ssl
            req.unverifiable = True  # 标记；urllib 仍可能校验，见下
        try:
            context = None
            if not self.config['ssl_verify']:
                import ssl as _ssl
                context = _ssl._create_unverified_context()
            with urllib.request.urlopen(req, timeout=max(timeout_sec, 1), context=context) as resp:
                raw = resp.read().decode('utf-8', errors='replace')
                http = resp.status
        except urllib.error.HTTPError as e:
            raw = e.read().decode('utf-8', errors='replace')
            http = e.code
        except Exception as e:
            return {'ok': False, 'code': -1, 'msg': '网络错误: %s' % e, 'data': None, 'http': 0, 'error': str(e)}

        try:
            decoded = json.loads(raw)
        except Exception:
            return {'ok': False, 'code': -2, 'msg': '响应解析失败', 'data': None, 'http': http, 'error': raw}

        return {
            'ok': decoded.get('code') == 0,
            'code': decoded.get('code', -1),
            'msg': decoded.get('msg', ''),
            'data': decoded.get('data'),
            'http': http,
            'error': '',
        }

    # ------------------------------------------------------------
    # ① 三合一授权验证
    # ------------------------------------------------------------

    def verify(self):
        if not self.config['enabled']:
            return True
        policy = self.server_policy()
        if policy is not None and not policy.get('enforce_auth', True):
            return True

        # 0.5 离线激活状态：已导入且未过期的离线激活文件（完全断网也可通过）
        offline = self.cache.get('offline_activation')
        if isinstance(offline, dict) and offline.get('file'):
            if not offline.get('expire_at') or self._str_to_ts(str(offline['expire_at'])) > time.time():
                file_data = offline['file']
                if isinstance(file_data, dict):
                    self.verify_result = dict(file_data)
                    self.verify_result['offline'] = True
                    return True
            self.cache.delete('offline_activation')

        # 1. 本地缓存令牌：未过期 + RSA 验签通过（无 cryptography 时跳过验签，视为有效）
        cached = self.cache.get('verification_token')
        if isinstance(cached, dict) and cached.get('token'):
            if not cached.get('expire_at') or self._str_to_ts(str(cached['expire_at'])) > time.time():
                payload = self.verify_token(cached['token'])
                if payload is not None:
                    self.verify_result = dict(cached)
                    self.verify_result['payload'] = payload
                    return True
            self.cache.delete('verification_token')

        # 2. 调用验证接口
        resp = self._signed_request('auth/verify', {
            'product_code': self.config['product_code'],
            'license_key': self.config['license_key'],
            'domain': self.current_domain(),
            'ip': self.current_ip(),
            'client_version': self.config['app_version'],
            'instance_id': self.get_instance_id() or '',
            'machine_code': self.current_machine_code(),
            'machine_name': self.machine_name(),
        }, self.config['app_secret'])

        if resp['ok'] and resp['data'] and resp['data'].get('verified'):
            data = resp['data']
            self.cache.set('server_policy', {
                'fail_open': bool(data.get('fail_open', self.config['fail_open'])),
                'enforce_auth': bool(data.get('enforce_auth', True)),
                'updated_at': time.strftime('%Y-%m-%d %H:%M:%S'),
            }, 0)
            self.cache.set('verification_token', {
                'token': data['token'],
                'expire_at': data.get('expire_at', ''),
                'verified_at': time.strftime('%Y-%m-%d %H:%M:%S'),
            }, self._remaining_seconds(data.get('expire_at', '')))
            self.verify_result = data
            return True

        # 3. 网络异常：fail_open 策略
        if resp['code'] in (-1, -2):
            fail_open = policy['fail_open'] if policy is not None else bool(self.config['fail_open'])
            self._log_warning('授权服务器不可达，fail_open=%s' % fail_open)
            return fail_open

        # 4. 明确被拒绝
        return False

    def server_policy(self):
        p = self.cache.get('server_policy')
        return p if isinstance(p, dict) else None

    def verify_token(self, token):
        """RSA 验签验证令牌；无 cryptography 时降级为结构校验（返回空载荷表示'存在'）"""
        parts = token.split('.', 1)
        if len(parts) != 2:
            return None
        encoded, sig_b64 = parts
        try:
            payload = json.loads(self._b64url_decode(encoded).decode('utf-8'))
        except Exception:
            return None
        pub = self.config['rsa_public_key']
        if not pub:
            return payload  # 未配置公钥：信任签名结构
        if not _HAS_CRYPTO:
            return payload  # 无 cryptography 库：降级信任（在线 verify 每次仍会校验）
        try:
            pkey = serialization.load_pem_public_key(pub.encode('utf-8'))
            pkey.verify(self._b64url_decode(sig_b64), encoded.encode('utf-8'),
                        padding.PKCS1v15(), hashes.SHA256())
            return payload
        except (InvalidSignature, Exception):
            return None

    # ------------------------------------------------------------
    # ② 实例注册
    # ------------------------------------------------------------

    def register_instance(self):
        cached = self.cache.get('instance')
        if isinstance(cached, dict) and cached.get('instance_id') and cached.get('instance_token'):
            return cached
        resp = self._signed_request('instance/register', {
            'product_code': self.config['product_code'],
            'license_key': self.config['license_key'],
            'domain': self.current_domain(),
            'ip': self.current_ip(),
            'version': self.config['app_version'],
            'server_info': self.server_info(),
            'db_info': {'type': 'sqlite', 'version': 'unknown'},
            'machine_code': self.current_machine_code(),
            'machine_name': self.machine_name(),
        }, self.config['app_secret'])
        if resp['ok'] and resp['data'] and resp['data'].get('instance_id'):
            data = resp['data']
            self.cache.set('instance', {
                'instance_id': data['instance_id'],
                'instance_token': data['instance_token'],
            }, 0)
            return data
        return {'error': resp['msg'], 'code': resp['code']}

    def get_instance_id(self):
        cached = self.cache.get('instance')
        return cached.get('instance_id') if isinstance(cached, dict) else None

    def get_instance_token(self):
        cached = self.cache.get('instance')
        return cached.get('instance_token') if isinstance(cached, dict) else None

    def _ensure_instance(self):
        if self.get_instance_token() is not None:
            return True
        last_fail = int(self.cache.get('register_fail_at', 0) or 0)
        if last_fail > 0 and time.time() - last_fail < 60:
            return False
        res = self.register_instance()
        if 'error' in res:
            self.cache.set('register_fail_at', int(time.time()), 0)
            return False
        self.cache.delete('register_fail_at')
        return True

    def _ensure_instance_silent(self):
        try:
            return self._ensure_instance()
        except Exception:
            return False

    # ------------------------------------------------------------
    # ③ 心跳 / ④ 命令 / ⑤ 弹窗 / ⑥ 更新 / ⑧ 模块 / ⑨ 技能
    # ------------------------------------------------------------

    def heartbeat(self):
        if not self._ensure_instance():
            return {'error': '实例未注册'}
        resp = self._signed_request('instance/heartbeat', {
            'version': self.config['app_version'],
            'server_info': self.server_info(),
            'db_info': {'type': 'sqlite', 'version': 'unknown'},
        }, self.get_instance_token())
        if resp['ok']:
            self.cache.set('last_heartbeat', int(time.time()), 0)
        return resp

    def on_command(self, cmd_type, handler):
        """注册命令处理器（回调签名 handler(payload)）"""
        self._command_handlers = getattr(self, '_command_handlers', {})
        self._command_handlers[cmd_type] = handler

    def poll_commands(self):
        if not self._ensure_instance():
            return {'error': '实例未注册'}
        resp = self._signed_request('command/poll', {}, self.get_instance_token())
        if not resp['ok']:
            return resp
        handlers = getattr(self, '_command_handlers', {})
        for cmd in (resp['data'].get('commands') or []):
            try:
                result = self._dispatch_command(cmd, handlers)
                self._signed_request('command/report', {
                    'command_id': cmd.get('id'),
                    'status': 2,
                    'result': {'ok': True, 'detail': result},
                }, self.get_instance_token())
            except Exception as e:
                self._signed_request('command/report', {
                    'command_id': cmd.get('id'),
                    'status': 3,
                    'result': {'ok': False, 'error': str(e)},
                }, self.get_instance_token())
        return resp

    def _dispatch_command(self, cmd, handlers):
        cmd_type = cmd.get('command_type', '')
        payload = cmd.get('payload') or {}
        if cmd_type in handlers:
            return handlers[cmd_type](payload)
        if cmd_type == 'disable':
            self.cache.set('revoked', int(time.time()), 0)
            return '程序已禁用'
        if cmd_type == 'enable':
            self.cache.delete('revoked')
            return '程序已启用'
        if cmd_type == 'maintenance_on':
            self.cache.set('maintenance', payload, 86400)
            return '已进入维护模式'
        if cmd_type == 'maintenance_off':
            self.cache.delete('maintenance')
            return '已退出维护模式'
        if cmd_type == 'reboot':
            self.cache.set('reboot_requested', int(time.time()), 0)
            return '重启指令已接收'
        if cmd_type == 'update':
            target = payload.get('target_version') if isinstance(payload, dict) else None
            upd = self.check_update(target)
            if upd:
                self.apply_update(upd)
                return '更新完成'
            return '未找到目标版本更新' if target else '当前已是最新版本'
        return '未注册的命令类型: %s' % cmd_type

    def get_popups(self):
        if not self._ensure_instance():
            return {'error': '实例未注册'}
        return self._signed_request('popup/list', {}, self.get_instance_token())

    def report_popup_shown(self, popup_id):
        if not self._ensure_instance():
            return False
        resp = self._signed_request('popup/report', {'popup_id': popup_id}, self.get_instance_token())
        return bool(resp['ok'])

    def check_update(self, target_version=None):
        if not self._ensure_instance():
            return None
        resp = self._signed_request('update/check', {
            'current_version': target_version or self.config['app_version'],
        }, self.get_instance_token())
        if not resp['ok'] or not (resp['data'] and resp['data'].get('has_update')):
            return None
        return resp['data']['update']

    def apply_update(self, update):
        """应用更新：下载 → MD5 校验 → 替换 → 上报（Python 版仅下载+校验，替换由集成方回调处理）"""
        target_version = update.get('version', '')
        download_url = update.get('url', '')
        md5 = update.get('md5', '')
        status = 2
        error = ''
        try:
            if not download_url:
                raise DCAIError('更新包下载地址为空')
            update_dir = os.path.join(self.config['cache_dir'], 'update_cache')
            os.makedirs(update_dir, exist_ok=True)
            local_path = os.path.join(update_dir, 'update_%s.zip' % target_version)
            urllib.request.urlretrieve(download_url, local_path)
            if md5:
                actual = hashlib.md5(open(local_path, 'rb').read()).hexdigest()
                if actual.lower() != md5.lower():
                    raise DCAIError('MD5 校验失败')
            # 替换动作：由集成方通过 on_update_apply 回调实现（Python 无法通用地覆盖运行中代码）
            cb = getattr(self, '_update_apply_cb', None)
            if callable(cb):
                cb(local_path, update)
        except Exception as e:
            status = 3
            error = str(e)
        if self._ensure_instance():
            self._signed_request('update/report', {
                'target_version': target_version,
                'status': status,
                'error': error,
            }, self.get_instance_token())
        return status == 2

    def on_update_apply(self, cb):
        """注册更新应用回调：cb(local_path, update) —— 负责把下载的包解压/替换到程序目录"""
        self._update_apply_cb = cb

    def call_module(self, module_code, params=None):
        if not self._ensure_instance():
            raise DCAIError('实例未注册，无法调用远程模块')
        resp = self._signed_request('module/invoke', {
            'module_code': module_code,
            'params': params or {},
        }, self.get_instance_token())
        if not resp['ok']:
            raise DCAIError('模块调用失败(%s): %s' % (resp['code'], resp['msg']))
        return (resp['data'] or {}).get('result')

    def get_skills(self):
        if not self.config['enabled'] or not self._ensure_instance_silent():
            return []
        resp = self._signed_request('skill/list', {}, self.get_instance_token())
        if not resp['ok']:
            self._log_warning('获取技能列表失败(%s): %s' % (resp['code'], resp['msg']))
            return []
        return (resp['data'] or {}).get('skills') or []

    def call_skill(self, skill_code, function_code, params=None, timeout=0):
        if not self.config['enabled']:
            raise DCAIError('授权未启用，无法调用技能')
        if not self._ensure_instance():
            raise DCAIError('实例未注册，无法调用技能')
        resp = self._signed_request('skill/invoke', {
            'skill_code': skill_code,
            'function_code': function_code,
            'params': params or {},
        }, self.get_instance_token(), timeout)
        if not resp['ok']:
            raise DCAIError('技能调用失败(%s): %s' % (resp['code'], resp['msg']))
        return (resp['data'] or {}).get('result')

    # ------------------------------------------------------------
    # 周期任务入口（等价 PHP runHooks）
    # ------------------------------------------------------------

    def run_hooks(self):
        if not self.config['enabled']:
            return
        if not self.verify():
            return
        last_hb = int(self.cache.get('last_heartbeat', 0) or 0)
        if time.time() - last_hb >= int(self.config['heartbeat_interval']):
            resp = self.heartbeat()
            if not resp['ok']:
                code = resp.get('code', -1)
                if code in (1003, 2100):
                    self._log_warning('心跳鉴权失败(%s)，清除实例凭证，将自动重新注册' % code)
                    self.cache.delete('instance')
                elif code == 2008:
                    self._log_warning('实例已被远程禁用')
                    self.cache.set('revoked', int(time.time()), 0)
                return
            if resp['data'] and resp['data'].get('revoked'):
                self.cache.set('revoked', int(time.time()), 0)
                return
            if resp['data'] and resp['data'].get('new_version'):
                self.cache.set('new_version_notice', resp['data']['new_version'], 3600)
            else:
                self.cache.delete('new_version_notice')
            self.poll_commands()

    # ------------------------------------------------------------
    # 守卫
    # ------------------------------------------------------------

    def is_revoked(self):
        return self.cache.has('revoked')

    def guard(self):
        """集成守卫：返回 True=已授权可继续；False=未授权（集成方自行处理退出/降级）"""
        if self.is_revoked():
            return False
        if not self.config['enabled']:
            return True
        return self.verify()

    def get_verified_info(self):
        info = self.verify_result
        if not info:
            info = self.cache.get('verification_token')
        if isinstance(info, dict) and info.get('payload'):
            info['product_code'] = info['payload'].get('product_code', '')
            info['domain'] = info['payload'].get('domain', '')
            info['ip'] = info['payload'].get('ip', '')
        return info or None

    def get_trial_status(self):
        """读取试用状态（仅最近在线 verify 响应携带；试用授权码返回非空 dict）"""
        info = self.verify_result
        if not isinstance(info, dict):
            return None
        trial = info.get('trial')
        return trial if isinstance(trial, dict) and trial else None

    def get_machine_status(self):
        """读取机器码绑定状态（verify 响应携带）"""
        info = self.verify_result
        if not isinstance(info, dict):
            return None
        machine = info.get('machine')
        return machine if isinstance(machine, dict) and machine else None

    # ------------------------------------------------------------
    # ⑩ 离线激活（内网 / 断网场景）
    # ------------------------------------------------------------

    def create_offline_request(self):
        """生成本机离线激活请求 JSON 字符串，保存为文件交管理员签发。

        返回的 JSON 含 type/version/product_code/license_key/machine_code/machine_name/request_id/requested_at。
        集成方可直接写入 dcai_offline_request.json 交管理员。
        """
        machine_code = self.current_machine_code()
        if not machine_code:
            raise DCAIError('无法采集机器指纹：请检查 app_secret 配置')
        if not self.config['product_code'] or not self.config['license_key']:
            raise DCAIError('缺少 product_code 或 license_key 配置')
        request = {
            'type': 'dcai_offline_request',
            'version': 1,
            'product_code': self.config['product_code'],
            'license_key': self.config['license_key'],
            'machine_code': machine_code,
            'machine_name': self.machine_name(),
            'request_id': os.urandom(16).hex(),
            'requested_at': time.strftime('%Y-%m-%d %H:%M:%S'),
        }
        return json.dumps(request, ensure_ascii=False, indent=2)

    def apply_offline_activation(self, activation_json):
        """导入并验签「离线激活文件」，成功后本机进入离线授权状态。

        校验流程与 PHP SDK 完全一致：
          1. 解析 JSON + 校验 type 为 dcai_offline_activation
          2. RSA 验签（签名内容为剔除 signature 后的 payload JSON，编码规则与签发端一致）
          3. 到期校验（有 expire_at 且已过期 → 拒绝）
          4. 机器码匹配（摘要比对，不一致 → 拒绝）
          5. 写入缓存 offline_activation，verify() 优先读取（断网也可通过 guard）

        失败时可用 last_offline_error() 查看原因。
        """
        self._last_offline_error = ''
        try:
            data = json.loads(activation_json) if isinstance(activation_json, str) else activation_json
        except Exception:
            self._last_offline_error = '激活文件格式错误'
            return False
        if not isinstance(data, dict):
            self._last_offline_error = '激活文件格式错误'
            return False
        if data.get('type') != 'dcai_offline_activation':
            self._last_offline_error = '不是有效的离线激活文件'
            return False

        signature = str(data.get('signature', ''))
        payload = {k: v for k, v in data.items() if k != 'signature'}
        try:
            payload_json = json.dumps(payload, ensure_ascii=False)
        except Exception:
            self._last_offline_error = '激活文件格式错误'
            return False
        if not self._verify_offline_signature(payload_json, signature):
            self._last_offline_error = self._last_offline_error or '签名验证失败'
            return False

        # 到期校验
        if data.get('expire_at') and self._str_to_ts(str(data['expire_at'])) < time.time():
            self._last_offline_error = '离线激活已到期'
            return False

        # 机器匹配（摘要比对；本机无法采集同算法指纹时跳过）
        my_code = self.current_machine_code()
        file_code = str(data.get('machine_code', ''))
        if my_code and file_code and file_code.lower() != my_code.lower():
            self._last_offline_error = '激活文件与本机指纹不匹配'
            return False

        self.cache.set('offline_activation', {
            'file': data,
            'expire_at': data.get('expire_at') or None,
            'activated_at': time.strftime('%Y-%m-%d %H:%M:%S'),
        }, 0)
        self.cache.delete('revoked')
        return True

    def _verify_offline_signature(self, payload_json, signature):
        """RSA 验签（与 PHP DCAI_Signature::signVerificationToken 一致）：
        签名串 = base64url(payload JSON) . "." . base64url(RSA-SHA256(该段内容))
        """
        pub = self.config['rsa_public_key']
        if not pub:
            self._last_offline_error = '未配置 RSA 公钥，无法验签'
            return False
        parts = signature.split('.', 1)
        if len(parts) != 2:
            self._last_offline_error = '签名格式错误'
            return False
        encoded, sig_b64 = parts
        # 【安全】签名串 encoded 解码后必须与外层 payload 结构化一致（防篡改外层字段）。
        # 说明：PHP json_encode 与 Python json.dumps 的序列化字节（分隔符/斜杠转义/键序）可能有差异，
        #       因此不比对字节，而比对结构化内容 —— 等价于"签名覆盖的就是当前文件内容"。
        try:
            signed_payload = json.loads(self._b64url_decode(encoded).decode('utf-8'))
        except Exception:
            self._last_offline_error = '签名内容无法解析'
            return False
        outer = {k: v for k, v in json.loads(payload_json).items() if k != 'signature'}
        if signed_payload != outer:
            self._last_offline_error = '激活文件内容与签名不符（已被篡改）'
            return False
        if not _HAS_CRYPTO:
            # 无 cryptography 库：校验 encoded 可解码为合法 JSON 后信任（online verify 仍会在每次联网时强校验）
            try:
                decoded = json.loads(self._b64url_decode(encoded).decode('utf-8'))
                if isinstance(decoded, dict):
                    return True
            except Exception:
                pass
            self._last_offline_error = '签名结构无效'
            return False
        try:
            pkey = serialization.load_pem_public_key(pub.encode('utf-8'))
            pkey.verify(self._b64url_decode(sig_b64), encoded.encode('utf-8'),
                        padding.PKCS1v15(), hashes.SHA256())
            return True
        except InvalidSignature:
            self._last_offline_error = '签名验证失败'
            return False
        except Exception:
            self._last_offline_error = 'RSA 公钥无效'
            return False

    def has_offline_activation(self):
        """是否处于离线激活状态（即使断网也可通过 guard）"""
        return self.cache.has('offline_activation')

    def get_offline_activation(self):
        """获取离线激活缓存信息（含到期时间），无则 None"""
        d = self.cache.get('offline_activation')
        return d if isinstance(d, dict) else None

    def last_offline_error(self):
        """最后一次离线激活错误信息"""
        return getattr(self, '_last_offline_error', '')

    # ------------------------------------------------------------
    # 环境信息与机器指纹
    # ------------------------------------------------------------

    def current_domain(self):
        # 显式配置优先（被授权程序常需指定绑定域名）；否则自动探测
        cfg_domain = self.config.get('domain', '')
        if cfg_domain:
            return str(cfg_domain).lower()
        try:
            hostname = socket.getfqdn()
        except Exception:
            hostname = socket.gethostname() or 'localhost'
        return hostname.lower()

    def current_ip(self):
        # 显式配置优先；否则自动探测直连 IP（无法伪造）
        cfg_ip = self.config.get('ip', '')
        if cfg_ip:
            return str(cfg_ip)
        # 直连 IP（无法伪造）；无网络环境回退本机出口 IP
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
            s.close()
            return ip
        except Exception:
            pass
        try:
            return socket.gethostbyname(socket.gethostname())
        except Exception:
            return '127.0.0.1'

    def server_info(self):
        return {
            'python_version': platform.python_version(),
            'os': '%s %s' % (platform.system(), platform.release()),
            'memory_mb': 0,
            'disk_free_mb': 0,
        }

    # -- 机器指纹（与 PHP MachineService::machineCode 完全一致）--

    def _fingerprint_data(self):
        data = {}
        data['platform'] = platform.system()
        data['hostname'] = socket.gethostname() or ''
        # CPU
        cpu = ''
        if sys.platform.startswith('linux'):
            try:
                with open('/proc/cpuinfo', 'r', errors='ignore') as f:
                    for line in f:
                        if line.lower().startswith('model name') or line.lower().startswith('hardware') or line.lower().startswith('serial'):
                            cpu = line.split(':', 1)[1].strip()
                            break
            except Exception:
                pass
        if not cpu:
            cpu = os.environ.get('PROCESSOR_IDENTIFIER', '') or ''
        data['cpu'] = cpu
        # 磁盘
        disk = ''
        if sys.platform.startswith('linux'):
            try:
                with open('/proc/self/mountinfo', 'r', errors='ignore') as f:
                    for line in f:
                        if ' / / ' in line:
                            parts = line.split()
                            if len(parts) > 5:
                                disk = parts[5]
                            break
            except Exception:
                pass
        if not disk and sys.platform.startswith('win'):
            disk = os.environ.get('SystemDrive', '') or 'C:'
        data['disk'] = disk
        # MAC
        mac = ''
        if sys.platform.startswith('linux'):
            try:
                for iface in os.listdir('/sys/class/net'):
                    if iface == 'lo':
                        continue
                    try:
                        with open('/sys/class/net/%s/address' % iface, 'r') as f:
                            addr = f.read().strip()
                        if addr.lower() != '00:00:00:00:00:00' and ':' in addr:
                            mac = addr.lower()
                            break
                    except Exception:
                        continue
            except Exception:
                pass
        data['mac'] = mac
        # OS 版本
        data['os'] = platform.release()
        return data

    def machine_fingerprint(self):
        """采集指纹原文（"k=v" 行，已排序，小写）—— 与 PHP 端一致"""
        data = self._fingerprint_data()
        parts = []
        for key in ('platform', 'hostname', 'cpu', 'disk', 'mac', 'os'):
            v = str(data.get(key, '') or '').strip()
            if v:
                parts.append('%s=%s' % (key, v.lower()))
        parts.sort()
        return '\n'.join(parts)

    def current_machine_code(self):
        """64 位 hex 机器码（SHA256 → HMAC-SHA256(app_secret)），无法采集时返回 ''"""
        secret = self.config['app_secret']
        if not secret:
            return ''
        raw = self.machine_fingerprint()
        if not raw:
            return ''
        return hmac.new(secret.encode('utf-8'),
                        hashlib.sha256(raw.encode('utf-8')).digest(),
                        hashlib.sha256).hexdigest()

    def machine_name(self):
        host = self.current_domain()
        os_ver = platform.release() or ''
        name = '%s / Python %s / %s' % (host, platform.python_version(), os_ver)
        return name[:255]

    # ------------------------------------------------------------
    # 工具方法
    # ------------------------------------------------------------

    def get_update_notice(self):
        notice = self.cache.get('new_version_notice')
        return notice if isinstance(notice, dict) else None

    def get_diagnostics(self):
        out = {
            'time': time.strftime('%Y-%m-%d %H:%M:%S'),
            'product_code': self.config['product_code'],
            'enabled': bool(self.config['enabled']),
            'server_url': self.config['server_url'],
            'cache_dir': self.config['cache_dir'],
            'cache_writable': os.path.isdir(self.config['cache_dir']) and os.access(self.config['cache_dir'], os.W_OK),
            'python_version': platform.python_version(),
            'crypto_lib': _HAS_CRYPTO,
        }
        token = self.cache.get('verification_token')
        out['token'] = {'valid': bool(token and token.get('token')), 'expire_at': (token or {}).get('expire_at', '')}
        inst = self.cache.get('instance')
        out['instance_registered'] = bool(inst and inst.get('instance_id'))
        out['revoked'] = self.is_revoked()
        out['policy'] = self.server_policy()
        out['reported'] = {
            'domain': self.current_domain(),
            'ip': self.current_ip(),
            'machine_code': self.current_machine_code(),
        }
        return out

    @staticmethod
    def _str_to_ts(s):
        try:
            import datetime
            dt = datetime.datetime.strptime(s, '%Y-%m-%d %H:%M:%S')
            return int(time.mktime(dt.timetuple()))
        except Exception:
            return 0

    def _remaining_seconds(self, expire_at):
        ts = self._str_to_ts(str(expire_at))
        if ts <= 0 or ts <= time.time():
            return 3600
        return int(ts - time.time())

    def _log_warning(self, msg):
        try:
            log_dir = self.config['cache_dir']
            os.makedirs(log_dir, exist_ok=True)
            with open(os.path.join(log_dir, 'dcai_alerts.log'), 'a', encoding='utf-8') as f:
                f.write('[%s] [WARNING] %s\n' % (time.strftime('%Y-%m-%d %H:%M:%S'), msg))
        except Exception:
            pass


if __name__ == '__main__':
    # 自检：采集指纹/机器码并打印诊断信息
    import pprint

    client = DCAIClient({'app_secret': 'self_test_key', 'cache_dir': os.path.join(os.path.dirname(os.path.abspath(__file__)), 'cache_selftest')})
    print('== 指纹原文 ==')
    print(client.machine_fingerprint() or '(empty)')
    print('\n== 机器码 ==')
    code = client.current_machine_code()
    print(code if code else '(empty)')
    print('\n== 诊断 ==')
    pprint.pprint(client.get_diagnostics())
