#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DCAI Python SDK 配置样例
=======================
复制为 dcai_config.py 并填写真实参数。
RSA 公钥用于本地校验"验证令牌"（可选，但强烈建议配置，离线防篡改）。
"""

DCAI_CONFIG = {
    # 授权服务器地址（必须以 /api/v1/ 结尾）
    'server_url': 'https://your-server.com/api/v1/',

    # 产品编码（后台创建产品时生成）
    'product_code': 'your_product',

    # 应用密钥（后台「SDK 配置」获取，用于请求签名）
    'app_secret': 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    # 授权码
    'license_key': 'DCAI-XXXX-XXXX-XXXX-XXXX-XXXX',

    # 授权开关（false=关闭授权校验，默认放行）
    'enabled': True,

    # 网络异常时的兜底策略（true=放行，false=拒绝）
    'fail_open': True,

    # 本地缓存目录（授权令牌/实例凭证等）
    'cache_dir': './cache_py/',

    # RSA 公钥（验证令牌验签用）
    'rsa_public_key': '',
    # 'rsa_public_key': '''-----BEGIN PUBLIC KEY-----
    # MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...
    # -----END PUBLIC KEY-----''',

    # 网络超时（秒）
    'http_timeout': 5,

    # HTTPS 证书校验
    'ssl_verify': True,

    # 心跳间隔（秒）
    'heartbeat_interval': 60,

    # 被授权程序版本号（用于更新检测）
    'app_version': '1.0.0',
}