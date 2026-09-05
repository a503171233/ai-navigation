<?php
/**
 * DCAI SDK 配置样例
 * 复制本文件为 dcai_config.php 并填入实际配置。
 */

return [
    // 授权系统地址（以 / 结尾）
    'server_url'    => 'http://127.0.0.1:8080/api/v1/',

    // 产品编码（授权系统后台创建）
    'product_code'  => 'shop_v2',

    // SDK 内置签名密钥（授权系统后台「系统设置」中查看）
    'app_secret'    => '66b097d9828db3f130a4dc84bbd6f27907def20976bab67b59cd1561b4975533',

    // 客户授权码
    'license_key'   => 'DCAI-XXXXX-XXXXX-XXXXX-XXXXX',

    // 授权开关：false = 不发起任何请求，verify() 直接返回已授权（默认放行）
    'enabled'       => true,

    // 授权系统不可达时的放行策略（产品 fail_open 的客户端镜像）
    'fail_open'     => true,

    // 本地缓存目录（需可写）
    'cache_dir'     => __DIR__ . '/cache/',

    // 验证令牌验签公钥（授权系统后台「系统设置」中复制）
    'rsa_public_key' => '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0FXa4ElFZ7320NWIQOw1
s72aRfm8JB5/vbrcgIWul/3Cg5897sT6pOg4/n5m2YTIKyjT+8CoFUd/G40Al6I0
31FPiULX3F2v4etQG8TaSgplY51el8uZUcXDmKSUIyD4KDmLqeUXbtLncu9vcZwd
mlDv9b2dW1xMPjK8lUDTRoI89UDQaHzDDCAvMhHbIizN+4aYuotM1Li10TS61hr6
lAz/PZyrfBXTsHzu/T0rioShjqzHB4/oYvKlhcpqE12ukxvS34j4ix7y699/WNVo
vTheoEx4VT/5wV9JEq/C2HENofAM6bGoDx0BkJi4JlflyNQU+FRJqF20yuZYsQkg
dQIDAQAB
-----END PUBLIC KEY-----',

    // HTTP 请求超时（秒）
    'http_timeout'  => 5,

    // TLS 证书校验（默认开启）。若授权系统使用自签证书，请设为 false，或配置 ca_bundle 指定 CA 证书
    'ssl_verify'    => true,
    // CA 证书路径（可选，默认使用系统根证书）
    'ca_bundle'     => '',

    // 是否信任反向代理转发头（X-Forwarded-For / X-Real-IP）：
    // 仅当本程序明确部署在 Nginx/负载均衡之后时设为 true，否则保持 false，
    // 防止客户端伪造转发头绕过授权系统的 IP 白名单。
    'proxy_headers' => false,

    // 心跳间隔（秒）
    'heartbeat_interval' => 60,

    // 更新包下载目录
    'update_dir'    => __DIR__ . '/update_cache/',

    // 被授权程序根目录（更新时用于替换文件）
    'app_root'      => dirname(__DIR__),

    // 当前程序版本（每次发布更新时同步修改）
    'app_version'   => '1.0.0',
];
