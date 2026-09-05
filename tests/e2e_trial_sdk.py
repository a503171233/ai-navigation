#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Python SDK 试用状态端到端验证：试用授权码 verify 应返回 trial 信息"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'sdk'))
from dcai_client import DCAIClient  # noqa: E402

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
    'license_key': 'DCAI-6WX8-5RXT-BGBB-XDHF-X5AR',  # 试用授权码
    'app_secret': '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533',
    'domain': '127.0.0.1',
    'ip': '127.0.0.1',
    'rsa_public_key': PUB_KEY,
    'enabled': True,
    'fail_open': True,
    'cache_dir': os.path.join(tempfile.mkdtemp(prefix='dcai_trial_'), 'cache'),
    'app_version': '1.3.0',
})

print('== 试用授权码 verify ==')
ok = client.verify()
print('verify:', ok)
assert ok is True, '试用授权码应验证通过'

trial = client.get_trial_status()
print('trial status:', trial)
assert trial is not None, '试用授权码 verify 应返回 trial 信息'
assert trial.get('is_trial') is True
assert trial.get('trial_days') == 7
assert trial.get('trial_remaining_days', 0) > 0
print('[PASS] 试用状态正确: %s 天, 剩 %s 天' % (trial['trial_days'], trial['trial_remaining_days']))

machine = client.get_machine_status()
print('machine status:', machine)
print('[PASS] 机器码状态: %s' % ('已上报' if machine and machine.get('bound') else '未上报'))
print('\nDONE trial SDK e2e')
