#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Python SDK 离线激活端到端验证（走真实授权服务器 API 通道）
========================================================
使用本地授权服务器的 offline/request API 签发离线激活文件，验证 Python SDK 完整闭环：
  1. Python SDK 生成本机激活请求（指纹与 PHP MachineService 一致）
  2. 通过 API offline/request 在线签发激活文件（真实服务器 + RSA 私钥签名）
  3. Python SDK 导入激活文件 → RSA 公钥验签 → 机器匹配 → 生效
  4. verify() 在离线态直接放行（断网可用）
  5. 篡改（改机器码）→ 拒绝
  6. 到期校验 → 拒绝
"""
import json
import os
import subprocess
import sys
import time
import urllib.request

sys.path.insert(0, 'D:/xiangmu/shouquan/sdk')
from dcai_client import DCAIClient, _HAS_CRYPTO

BASE = 'http://127.0.0.1:8080/api/v1/'
PHP = 'D:/VSC/php8.2/php-8.2.33-Win32-vs16-x64/php.exe'

# ---- 与 PHP 测试共用配置 ----
PUB_KEY = '''-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0FXa4ElFZ7320NWIQOw1
s72aRfm8JB5/vbrcgIWul/3Cg5897sT6pOg4/n5m2YTIKyjT+8CoFUd/G40Al6I0
31FPiULX3F2v4etQG8TaSgplY51el8uZUcXDmKSUIyD4KDmLqeUXbtLncu9vcZwd
mlDv9b2dW1xMPjK8lUDTRoI89UDQaHzDDCAvMhHbIizN+4aYuotM1Li10TS61hr6
lAz/PZyrfBXTsHzu/T0rioShjqzHB4/oYvKlhcpqE12ukxvS34j4ix7y699/WNVo
vTheoEx4VT/5wV9JEq/C2HENofAM6bGoDx0BkJi4JlflyNQU+FRJqF20yuZYsQkg
dQIDAQAB
-----END PUBLIC KEY-----'''
APP_SECRET = '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533'

# 取一个有效授权码（直接从 DB 拿，保持与 PHP 测试一致）
# 用 DEBUG 通道：本地 DB 直接查询
import sqlite3  # noqa  - 不用，这里是 MySQL，改走 PHP CLI 查询

def db_query(sql):
    """通过 PHP CLI 查询本地 MySQL（测试用具）；跳过 PHP startup warning 行"""
    code = (
        "require_once 'D:/xiangmu/shouquan/core/Bootstrap.php';"
        " $r = dcai_db()->queryOne(" + json.dumps(sql) + ");"
        " echo json_encode($r);"
    )
    r = subprocess.run([PHP, '-r', code], capture_output=True, text=True)
    out = r.stdout.strip()
    # 提取首个 '{' 到行尾（跳过 PHP Warning / Xdebug 等 stderr 或 stdout 前导行）
    idx = out.find('{')
    out = out[idx:] if idx >= 0 else ''
    return json.loads(out) if out else {}

PASS = 0
FAIL = 0

def check(name, ok, extra=''):
    global PASS, FAIL
    if ok:
        PASS += 1
        print('  [PASS] %s %s' % (name, extra))
    else:
        FAIL += 1
        print('  [FAIL] %s %s' % (name, extra))

# 先取 license + product
license = db_query(
    "SELECT l.* FROM licenses l WHERE l.status = 1 AND l.expire_at > NOW() ORDER BY l.id DESC LIMIT 1"
)
if not license:
    print('[FAIL] 无可用授权码')
    sys.exit(1)
import subprocess
r = subprocess.run(
    [PHP, '-r',
     "require_once 'D:/xiangmu/shouquan/core/Bootstrap.php'; $l=dcai_db()->queryOne('SELECT * FROM licenses WHERE id=" + str(license['id']) + "'); $p=dcai_db()->queryOne('SELECT * FROM products WHERE id='.(int)$l['product_id']); echo $p['product_code'];"],
    capture_output=True, text=True
)
# 过滤 PHP warning / Xdebug 前导行，取最后一个有效行
lines = [ln.strip() for ln in r.stdout.strip().splitlines()
         if ln.strip() and 'Warning:' not in ln and 'Xdebug:' not in ln]
pid = lines[-1] if lines else ''
product_code = pid
print('[SETUP] 授权码 %s 产品 %s' % (license['license_key'], product_code))

# 独立缓存目录（模拟真实客户机）
cache_dir = 'D:/xiangmu/shouquan/tests/tmp/offline_py_cache_%s' % os.urandom(3).hex()
os.makedirs(cache_dir, exist_ok=True)

client = DCAIClient({
    'server_url': BASE,
    'product_code': product_code,
    'license_key': license['license_key'],
    'app_secret': APP_SECRET,
    'rsa_public_key': PUB_KEY,
    'enabled': True,
    'fail_open': True,
    'cache_dir': cache_dir,
    'app_version': '1.3.0',
})

print('\n=== 1. Python SDK 生成本机激活请求 ===')
req_json = client.create_offline_request()
req = json.loads(req_json)
check('请求生成', req.get('type') == 'dcai_offline_request', 'machine=' + req['machine_code'][:16] + '...')
check('请求含机器码', len(req.get('machine_code', '')) == 64)
check('请求含授权码', req.get('license_key') == license['license_key'])
check('请求含产品码', req.get('product_code') == product_code)

# 机器码与 SDK 内部一致
check('机器码与 current_machine_code 一致', req['machine_code'] == client.current_machine_code())

print('\n=== 2. 通过 API offline/request 在线签发 ===')
# offline/request 走 app_secret HMAC 签名认证（public action），复用 SDK 签名请求
resp_signed = client._signed_request('offline/request', {
    'product_code': req['product_code'],
    'license_key': req['license_key'],
    'machine_code': req['machine_code'],
    'machine_name': req['machine_name'],
}, client.config['app_secret'])
check('API 签发成功', resp_signed.get('ok') is True and 'file_json' in resp_signed.get('data', {}), resp_signed.get('msg', ''))
api = resp_signed

if api.get('ok'):
    activation_json = api['data']['file_json']
    activation = json.loads(activation_json)
    check('激活文件类型', activation.get('type') == 'dcai_offline_activation')
    check('激活文件签名存在', bool(activation.get('signature')))
    check('激活文件机器码一致', activation.get('machine_code') == req['machine_code'])

    print('\n=== 3. Python SDK 导入激活文件（RSA 验签 + 机器匹配） ===')
    ok = client.apply_offline_activation(activation_json)
    check('导入成功', ok, client.last_offline_error())
    check('has_offline_activation()=true', client.has_offline_activation())

    print('\n=== 4. 离线态 verify() 直接放行（断网可用） ===')
    # 直接把 server_url 指向不可达地址，模拟完全断网
    client.config['server_url'] = 'http://127.0.0.1:9/api/v1/'
    v = client.verify()
    check('verify() 通过（离线态）', v is True)
    check('verify_result 标记 offline', client.verify_result.get('offline') is True)
    info = client.get_offline_activation()
    check('get_offline_activation() 有到期信息', info is not None and 'file' in info)

    print('\n=== 5. 篡改（换成其它机器码）→ 拒绝 ===')
    tampered = dict(activation)
    tampered['machine_code'] = 'f' * 64
    ok2 = client.apply_offline_activation(json.dumps(tampered, ensure_ascii=False))
    check('篡改机器码被拒绝', ok2 is False, client.last_offline_error())

    print('\n=== 6. 到期校验 → 拒绝 ===')
    expired = dict(activation)
    expired['expire_at'] = '2000-01-01 00:00:00'
    # 签名字段保留（未篡改签名则验签通过，但到期被拒）
    ok3 = client.apply_offline_activation(json.dumps(expired, ensure_ascii=False))
    check('过期激活文件被拒绝', ok3 is False, client.last_offline_error())

# ---- 清理 ----
client.cache.delete('offline_activation')
try:
    os.system('rmdir /S /Q "%s" 2>nul' % cache_dir)
except Exception:
    pass
subprocess.run(
    [PHP, '-r',
     "require_once 'D:/xiangmu/shouquan/core/Bootstrap.php'; dcai_db()->execute('DELETE FROM offline_activations WHERE machine_code = ?', ['" + req['machine_code'] + "']); echo 'cleaned';"],
    capture_output=True, text=True
)

print('\n结果: %d 通过, %d 失败' % (PASS, FAIL))
print('DONE')
sys.exit(0 if FAIL == 0 else 1)