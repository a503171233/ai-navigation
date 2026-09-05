#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DCAI Python SDK 单元测试
========================
运行: python tests/test_python_sdk.py
覆盖: 签名算法(与PHP对拍)、机器指纹算法(与PHP对拍)、缓存、命令分发、令牌RSA验签
"""
import hashlib
import hmac
import json
import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdk'))
from dcai_client import DCAIClient, _Cache, DCAIError  # noqa: E402

PASS = 0
FAIL = 0


def check(name, cond):
    global PASS, FAIL
    if cond:
        PASS += 1
        print('[PASS] %s' % name)
    else:
        FAIL += 1
        print('[FAIL] %s' % name)


# ---------- 1. 签名算法（与 PHP DCAI_Signature::make 对拍） ----------
# PHP: payload = timestamp . "\n" . nonce . "\n" . hash('sha256', $body)
#      sign = hash_hmac('sha256', payload, secret)
def php_sign(secret, timestamp, nonce, body):
    payload = '%d\n%s\n%s' % (timestamp, nonce, hashlib.sha256(body.encode('utf-8')).hexdigest())
    return hmac.new(secret.encode('utf-8'), payload.encode('utf-8'), hashlib.sha256).hexdigest()


secret = 'test_secret_abc123'
ts = 1700000000
nonce = 'a1b2c3d4e5f60718'
body = '{"product_code":"demo","license_key":"DCAI-ABC1-DEF2-3456"}'

py_sign = DCAIClient._sign(secret, ts, nonce, body)
php_sign_val = php_sign(secret, ts, nonce, body)
check('HMAC 签名与 PHP 一致', py_sign == php_sign_val)
check('签名为 64 位 hex', len(py_sign) == 64 and all(c in '0123456789abcdef' for c in py_sign))

# ---------- 2. base64url 编解码与 PHP 一致 ----------
import base64
raw = json.dumps({'product_code': 'demo'}, ensure_ascii=False).encode('utf-8')
b64u = DCAIClient._b64url_encode(raw)
# PHP: base64UrlEncode 是 rtrim(strtr(base64_encode(data), '+/', '-_'), '=')
std_b64 = base64.b64encode(raw).decode('ascii').rstrip('=').replace('+', '-').replace('/', '_')
check('base64url 编码与 PHP 一致', b64u == std_b64)
check('base64url 解码还原', DCAIClient._b64url_decode(b64u) == raw)

# ---------- 3. 机器指纹算法（与 PHP MachineService::machineCode 对拍） ----------
def php_machine_code(data, sk):
    parts = []
    for k in ('platform', 'hostname', 'cpu', 'disk', 'mac', 'os'):
        v = str(data.get(k, '') or '').strip()
        if v:
            parts.append('%s=%s' % (k, v.lower()))
    parts.sort()
    raw = '\n'.join(parts)
    return hmac.new(sk.encode('utf-8'), hashlib.sha256(raw.encode('utf-8')).digest(), hashlib.sha256).hexdigest()


finger_data = {
    'platform': 'Linux',
    'hostname': 'web-01',
    'cpu': 'Intel(R) Xeon(R) CPU E5-2680 v4',
    'disk': '/dev/sda1',
    'mac': '00:1a:2b:3c:4d:5e',
    'os': '5.15.0-91-generic',
}
client = DCAIClient({'app_secret': 'test_secret_abc123'})
client._fingerprint_data = lambda: dict(finger_data)
fp = client.machine_fingerprint()
mc_py = client.current_machine_code()
mc_php = php_machine_code(finger_data, 'test_secret_abc123')
check('机器指纹原文规范化', fp == 'cpu=intel(r) xeon(r) cpu e5-2680 v4\ndisk=/dev/sda1\nhostname=web-01\nmac=00:1a:2b:3c:4d:5e\nos=5.15.0-91-generic\nplatform=linux')
check('机器码与 PHP 算法一致', mc_py == mc_php)
check('机器码为 64 位 hex', len(mc_py) == 64 and all(c in '0123456789abcdef' for c in mc_py))

# 字段顺序不影响结果
finger_data2 = dict(finger_data)
client2 = DCAIClient({'app_secret': 'test_secret_abc123'})
client2._fingerprint_data = lambda: dict(finger_data2)
check('字段顺序不影响机器码', client2.current_machine_code() == mc_py)

# 换一个指纹 → 机器码变化
finger_data3 = dict(finger_data, hostname='web-02')
client3 = DCAIClient({'app_secret': 'test_secret_abc123'})
client3._fingerprint_data = lambda: dict(finger_data3)
check('换机后机器码变化', client3.current_machine_code() != mc_py)

# ---------- 4. 缓存 ----------
tmpdir = tempfile.mkdtemp(prefix='dcai_test_')
c = _Cache(tmpdir)
c.set('verification_token', {'token': 'abc', 'expire_at': '2030-01-01 00:00:00'}, 99999)
check('缓存写入读取', c.get('verification_token') == {'token': 'abc', 'expire_at': '2030-01-01 00:00:00'})
c.set('short', 'x', -1)  # 负 TTL → 与 PHP 一致：视为长期存储（非过期）
check('负 TTL 长期存储（与 PHP 一致）', c.get('short', 'MISS') == 'x')
c.set('expired', 'e', 1)
time.sleep(1.1)  # 等 1 秒 TTL 过期
check('缓存过期删除', c.get('expired', 'MISS') == 'MISS')
c.set('long', 'y', 0)
check('缓存长期存储', c.get('long') == 'y')
c.delete('long')
check('缓存删除', c.get('long', 'MISS') == 'MISS')

# ---------- 5. 验证令牌 RSA 验签（使用预生成密钥对） ----------
# 生成密钥对
from cryptography.hazmat.primitives.asymmetric import rsa, padding  # noqa: E402
from cryptography.hazmat.primitives import hashes  # noqa: E402
from cryptography.hazmat.primitives import serialization  # noqa: E402

if _HAS_CRYPTO := (True):
    # 用 PHP 相同方式生成令牌：base64url(JSON).base64url(RSA-SHA256 签名)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    priv_pem = key.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption(),
    ).decode('utf-8')
    pub_pem = key.public_key().public_bytes(
        serialization.Encoding.PEM,
        serialization.PublicFormat.SubjectPublicKeyInfo,
    ).decode('utf-8')

    payload = {'product_code': 'demo', 'license_hash': hashlib.sha256(b'KEY').hexdigest(),
               'domain': 'a.com', 'ip': '1.2.3.4', 'iat': ts, 'expire_at': ts + 3600}
    encoded = DCAIClient._b64url_encode(json.dumps(payload).encode('utf-8'))
    sig = key.sign(encoded.encode('utf-8'), padding.PKCS1v15(), hashes.SHA256())
    token = '%s.%s' % (encoded, DCAIClient._b64url_encode(sig))

    client4 = DCAIClient({'rsa_public_key': pub_pem})
    verified = client4.verify_token(token)
    check('RSA 验签通过（有效令牌）', verified is not None and verified.get('product_code') == 'demo')

    # 篡改载荷 → 验签失败
    bad_token = '%s.%s' % (DCAIClient._b64url_encode(json.dumps(dict(payload, domain='evil.com')).encode('utf-8')), DCAIClient._b64url_encode(sig))
    check('RSA 验签拒绝篡改令牌', client4.verify_token(bad_token) is None)

# ---------- 6. 命令分发 ----------
client5 = DCAIClient({'cache_dir': tmpdir})
handled = []


def on_foo(payload):
    handled.append(payload)
    return 'FOO_OK'


client5.on_command('foo', on_foo)
client5._command_handlers['foo'] = on_foo  # 明确设置
result = client5._dispatch_command({'id': 1, 'command_type': 'foo', 'payload': {'a': 1}}, {'foo': on_foo})
check('自定义命令分发', result == 'FOO_OK' and handled == [{'a': 1}])
r = client5._dispatch_command({'id': 2, 'command_type': 'disable', 'payload': {}}, {})
check('内置 disable 命令', r == '程序已禁用' and client5.is_revoked())
client5._dispatch_command({'id': 3, 'command_type': 'enable', 'payload': {}}, {})
check('内置 enable 命令', not client5.is_revoked())

print('\n==== 结果: %d PASS, %d FAIL ====' % (PASS, FAIL))
sys.exit(0 if FAIL == 0 else 1)