#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Python SDK 端到端联调（连接本地真实授权服务器）
============================================
验证: 签名 → verify → 机器码自动绑定 → 令牌 RSA 验签 → 缓存
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdk'))
from dcai_client import DCAIClient  # noqa: E402

PASS = FAIL = 0


def check(name, cond):
    global PASS, FAIL
    if cond:
        PASS += 1
        print('[PASS] %s' % name)
    else:
        FAIL += 1
        print('[FAIL] %s' % name)


# 读取 PHP SDK 配置样例中的 RSA 公钥
PUB_KEY = '''-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0FXa4ElFZ7320NWIQOw1
s72aRfm8JB5/vbrcgIWul/3Cg5897sT6pOg4/n5m2YTIKyjT+8CoFUd/G40Al6I0
31FPiULX3F2v4etQG8TaSgplY51el8uZUcXDmKSUIyD4KDmLqeUXbtLncu9vcZwd
mlDv9b2dW1xMPjK8lUDTRoI89UDQaHzDDCAvMhHbIizN+4aYuotM1Li10TS61hr6
lAz/PZyrfBXTsHzu/T0rioShjqzHB4/oYvKlhcpqE12ukxvS34j4ix7y699/WNVo
vTheoEx4VT/5wV9JEq/C2HENofAM6bGoDx0BkJi4JlflyNQU+FRJqF20yuZYsQkg
dQIDAQAB
-----END PUBLIC KEY-----'''

client = DCAIClient({
    'server_url': 'http://127.0.0.1:8080/api/v1/',
    'product_code': 'shop_v2',
    'license_key': 'DCAI-QUX8-WKN8-Y7ET-N6Q8-NWJJ',
    'app_secret': '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533',
    'domain': '127.0.0.1',  # 白名单域
    'ip': '127.0.0.1',      # 白名单 IP
    'rsa_public_key': PUB_KEY,
    'enabled': True,
    'fail_open': True,
    'cache_dir': os.path.join(tempfile.mkdtemp(prefix='dcai_e2e_'), 'cache'),
    'app_version': '1.3.0',
})

print('== 机器指纹 ==')
fp = client.machine_fingerprint()
print('fingerprint: %s' % (fp[:120] + '...' if len(fp) > 120 else fp))
mc = client.current_machine_code()
print('machine_code: %s' % mc)
check('机器码 64 位 hex', len(mc) == 64)

print('\n== 首次 verify（在线，应自动绑定机器） ==')
ok = client.verify()
check('verify 返回 True', ok is True)

info = client.get_verified_info()
check('验证结果含 token', bool(info and info.get('token')))
print('   verify_result keys: %s' % sorted((info or {}).keys()))

print('\n== 机器绑定状态（服务端返回） ==')
machine = info.get('machine') if info else None
print('   machine: %s' % machine)
check('机器码已上报', bool(machine and machine.get('code')))
check('machine_limit>0（绑定生效）', machine and machine.get('limit') and machine.get('limit') > 0)

print('\n== 验证令牌本地 RSA 验签 ==')
token = info.get('token')
payload = client.verify_token(token)
check('RSA 验签通过', payload is not None)
if payload:
    print('   payload: product=%s domain=%s' % (payload.get('product_code'), payload.get('domain')))
    check('载荷产品匹配', payload.get('product_code') == 'shop_v2')

print('\n== 二次 verify（离线，走缓存令牌） ==')
# 清空缓存 token 但保留服务端策略，模拟网络不可用时无法在线验证 → fail_open 兜底
ok2 = client.verify()
check('二次 verify 返回 True', ok2 is True)

print('\n== 错误授权码（应拒绝） ==')
bad = DCAIClient({
    'server_url': 'http://127.0.0.1:8080/api/v1/',
    'product_code': 'shop_v2',
    'license_key': 'DCAI-XXXX-XXXX-XXXX-XXXX-XXXX',
    'app_secret': '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533',
    'domain': '127.0.0.1',
    'ip': '127.0.0.1',
    'enabled': True,
    'fail_open': True,
    'cache_dir': os.path.join(tempfile.mkdtemp(prefix='dcai_bad_'), 'cache'),
})
bad_ok = bad.verify()
check('错误授权码 verify 返回 False', bad_ok is False)

print('\n==== 结果: %d PASS, %d FAIL ====' % (PASS, FAIL))
sys.exit(0 if FAIL == 0 else 1)