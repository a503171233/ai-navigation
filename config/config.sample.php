<?php
/**
 * DCAI 授权系统 配置文件样例
 * 复制为 config.php 并填入真实值，或通过安装向导自动生成。
 * 注意：本文件包含敏感密钥，请勿提交到版本库，权限建议 600。
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'dcai_auth',
        'user'    => 'dcai',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'     => 'DCAI 授权系统',
        'version'  => '1.2.0', // 系统自身版本（系统级 OTA 升级后自动更新）
        'base_url' => 'http://127.0.0.1:8080', // 授权系统对外访问地址（不含结尾斜杠）
        'timezone' => 'Asia/Shanghai',
        'debug'    => false,
    ],
    'security' => [
        // 使用 openssl genrsa 生成，安装向导会自动创建
        'rsa_private_key' => '-----BEGIN PRIVATE KEY-----
...
-----END PRIVATE KEY-----',
        'rsa_public_key'  => '-----BEGIN PUBLIC KEY-----
...
-----END PUBLIC KEY-----',
        // 32 字节随机密钥，用于 instance_token 的 AES-256-CBC 加密
        'aes_key'          => '',
        // 授权令牌缓存秒数（产品级默认值，可在产品中覆盖）
        'verify_ttl'       => 3600,
        // 离线判定阈值(秒)
        'heartbeat_threshold' => 180,
        // 登录失败锁定
        'login_max_fail'   => 5,
        'login_lock_minutes' => 15,
        // 限流: verify 按 IP+授权码 维度; api 按实例维度
        'rate_limit'       => ['verify' => 30, 'api' => 120],
        // 请求时间戳最大偏差(秒)
        'timestamp_max_diff' => 300,
        // 防重放 nonce 有效期(秒)
        'nonce_ttl'        => 300,
        // 可信代理 IP 列表（支持 1.2.3.4 或 CIDR 1.2.3.0/24）：
        // 仅当 REMOTE_ADDR 命中此列表时，才信任 X-Forwarded-For / X-Real-IP 等转发头
        'trusted_proxies'  => [],
    ],
    'storage' => [
        'path' => dirname(__DIR__) . '/storage',
        // 上传大小上限 200MB
        'max_package_size' => 209715200,
        // 上传类型白名单
        'allowed_ext' => ['zip'],
    ],
    'sdk' => [
        // SDK 首次验证签名使用的 app_secret（安装向导自动生成，可后台重置）
        'app_secret' => '',
        // 心跳默认间隔(秒)，注册时返回给 SDK
        'heartbeat_interval' => 60,
    ],
    'log' => [
        'level' => 'info', // debug|info|warning|error
        'file'  => dirname(__DIR__) . '/storage/logs/app.log',
    ],
    'update_source' => [
        'enabled'    => 0,
        'manifest'   => '',  // 远程 manifest.json URL（例如 https://cdn.example.com/dcai/manifest.json）
        'auth_token' => '',  // 私有仓库可填 Bearer token
        'timeout'    => 15,
    ],
];
