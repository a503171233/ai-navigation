-- DCAI 授权系统 数据库结构 (MySQL 5.6 兼容 / InnoDB / utf8mb4)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 后台管理员
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL COMMENT '登录名',
  `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希(bcrypt)',
  `nickname` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `email` VARCHAR(100) NOT NULL DEFAULT '',
  `role` TINYINT NOT NULL DEFAULT 1 COMMENT '1=超级管理员 2=普通管理员',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用',
  `permissions` TEXT COMMENT '普通管理员权限点列表，每行一个(如 product/popup)',
  `twofa_secret` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Google身份验证器密钥(可选)',
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员';

-- 被授权程序产品
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_code` VARCHAR(50) NOT NULL COMMENT '产品唯一编码(如 shop_v2)',
  `name` VARCHAR(100) NOT NULL COMMENT '产品名称',
  `description` TEXT COMMENT '产品描述',
  `current_version` VARCHAR(30) NOT NULL DEFAULT '1.0.0' COMMENT '当前最新版本',
  `enforce_auth` TINYINT NOT NULL DEFAULT 0 COMMENT '0=未开启授权管控(默认放行) 1=开启三合一验证',
  `fail_open` TINYINT NOT NULL DEFAULT 1 COMMENT '1=授权系统不可达时默认放行 0=拒绝运行',
  `verify_ttl` INT NOT NULL DEFAULT 3600 COMMENT '授权令牌本地缓存秒数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=上架 0=下架',
  `for_sale` TINYINT NOT NULL DEFAULT 0 COMMENT '1=上架销售(商城可见可买) 0=仅内部',
  `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价(元), 0=不售卖',
  `price_unit` VARCHAR(20) NOT NULL DEFAULT 'year' COMMENT '计费单位 month/quarter/half_year/year/perpetual',
  `sale_icon` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '商城展示图标URL',
  `trial_enabled` TINYINT NOT NULL DEFAULT 0 COMMENT '1=开放自助申请试用 0=不开放',
  `trial_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '产品默认试用天数',
  `sale_intro` TEXT COMMENT '商城详情(富文本)',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='被授权程序产品';

-- 安装包
CREATE TABLE IF NOT EXISTS `install_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `version` VARCHAR(30) NOT NULL,
  `package_path` VARCHAR(255) NOT NULL COMMENT '安装包相对 storage 的路径',
  `package_md5` VARCHAR(64) NOT NULL,
  `package_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `manifest` TEXT COMMENT 'manifest.json 原始内容',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=可用 0=停用',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安装包';

-- 授权码
CREATE TABLE IF NOT EXISTS `licenses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_key` VARCHAR(64) NOT NULL COMMENT '授权码',
  `product_id` INT UNSIGNED NOT NULL,
  `customer_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '授权客户名称',
  `customer_email` VARCHAR(100) NOT NULL DEFAULT '',
  `allowed_domains` TEXT COMMENT '允许域名列表，每行一个，支持通配符 *.example.com',
  `allowed_ips` TEXT COMMENT '允许IP列表，每行一个，支持网段 1.2.3.0/24',
  `max_instances` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '最大部署实例数',
  `machine_limit` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '最大绑定机器数(0=不限制)',
  `trial_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '试用天数(0=正式授权; N=首次注册免费试用N天)',
  `expire_at` DATETIME NULL COMMENT '授权到期时间, NULL=永久',
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=有效 0=禁用',
  `source` TINYINT NOT NULL DEFAULT 0 COMMENT '0=后台手动 1=商城自动 2=人工发货',
  `buyer_id` INT UNSIGNED DEFAULT NULL COMMENT '购买者(买家)ID, 商城订单关联',
  `order_id` INT UNSIGNED DEFAULT NULL COMMENT '来源订单ID',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_key` (`license_key`),
  KEY `idx_product` (`product_id`),
  KEY `idx_status_expire` (`status`,`expire_at`),
  KEY `idx_buyer` (`buyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码';

-- 被授权程序实例（搭建现状）
CREATE TABLE IF NOT EXISTS `instances` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` VARCHAR(64) NOT NULL COMMENT '实例唯一ID(UUID)',
  `instance_token_enc` VARCHAR(255) NOT NULL COMMENT '实例令牌(AES加密存储)',
  `product_id` INT UNSIGNED NOT NULL,
  `license_id` INT UNSIGNED DEFAULT NULL,
  `domain` VARCHAR(191) NOT NULL COMMENT '部署域名',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '部署服务器IP',
  `machine_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '机器指纹(HMAC-SHA256摘要)',
  `version` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '程序当前版本',
  `server_info` TEXT COMMENT '服务器环境信息(JSON)',
  `db_info` TEXT COMMENT '数据库信息(JSON)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=在线 0=离线 2=已禁用(远程禁用)',
  `last_heartbeat_at` DATETIME DEFAULT NULL COMMENT '最后心跳时间',
  `first_seen_at` DATETIME NOT NULL COMMENT '首次注册时间',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_instance_id` (`instance_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_license` (`license_id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_last_heartbeat` (`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='被授权程序实例';

-- 机器码绑定（防授权码多机共用）
CREATE TABLE IF NOT EXISTS `machine_bindings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL COMMENT '授权码ID',
  `machine_code` VARCHAR(64) NOT NULL COMMENT '机器指纹(HMAC-SHA256)',
  `machine_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '机器名称(如 hostname)',
  `first_instance_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '首个绑定实例ID',
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=绑定 0=已解绑',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_machine` (`license_id`,`machine_code`),
  KEY `idx_license` (`license_id`),
  KEY `idx_machine` (`machine_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='机器码绑定';

-- 离线激活记录（内网断网场景）
CREATE TABLE IF NOT EXISTS `offline_activations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id` INT UNSIGNED NOT NULL COMMENT '授权码ID',
  `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `machine_code` VARCHAR(64) NOT NULL COMMENT '机器指纹',
  `machine_name` VARCHAR(255) NOT NULL DEFAULT '',
  `request_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '激活请求ID',
  `expire_at` DATETIME NULL COMMENT '离线授权到期时间(NULL=随授权码)',
  `activate_file` TEXT COMMENT '签发的激活文件内容(JSON, 含RSA签名)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已签发 0=已作废',
  `created_by` INT UNSIGNED DEFAULT NULL COMMENT '签发管理员ID',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_req_machine` (`request_id`,`machine_code`),
  KEY `idx_license` (`license_id`),
  KEY `idx_machine` (`machine_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='离线激活记录';

-- 远程管理命令
CREATE TABLE IF NOT EXISTS `instance_commands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` INT UNSIGNED NOT NULL,
  `command_type` VARCHAR(50) NOT NULL COMMENT 'disable|enable|config_push|maintenance_on|maintenance_off|reboot|update',
  `payload` TEXT COMMENT '命令参数(JSON)',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=待执行 1=已下发 2=执行成功 3=执行失败 4=超时',
  `result` TEXT COMMENT '执行结果(JSON)',
  `issued_by` INT UNSIGNED DEFAULT NULL COMMENT '下发管理员ID',
  `issued_at` DATETIME NOT NULL,
  `picked_at` DATETIME DEFAULT NULL,
  `executed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance_status` (`instance_id`,`status`),
  KEY `idx_issued` (`issued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程管理命令';

-- 弹窗
CREATE TABLE IF NOT EXISTS `popups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `content` TEXT NOT NULL COMMENT '弹窗内容，支持HTML',
  `popup_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=公告 2=通知 3=警示',
  `target_type` TINYINT NOT NULL DEFAULT 0 COMMENT '0=全部实例 1=指定实例',
  `target_instance_ids` TEXT COMMENT '指定实例ID列表(JSON数组)',
  `start_at` DATETIME DEFAULT NULL COMMENT '开始展示时间',
  `end_at` DATETIME DEFAULT NULL COMMENT '结束展示时间',
  `max_show_per_instance` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每实例最大展示次数 0=不限',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_status` (`product_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弹窗';

-- 弹窗展示记录
CREATE TABLE IF NOT EXISTS `popup_show_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `popup_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED NOT NULL,
  `shown_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_shown_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_popup_instance` (`popup_id`,`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弹窗展示记录';

-- 更新包
CREATE TABLE IF NOT EXISTS `updates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `version` VARCHAR(30) NOT NULL,
  `package_path` VARCHAR(255) NOT NULL,
  `package_md5` VARCHAR(64) NOT NULL,
  `package_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `min_version` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '允许升级的最低版本',
  `changelog` TEXT COMMENT '更新日志',
  `is_force` TINYINT NOT NULL DEFAULT 0 COMMENT '1=强制更新 0=可选',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已发布 0=已撤回',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_version` (`product_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新包';

-- 实例更新记录
CREATE TABLE IF NOT EXISTS `update_apply_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `update_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED NOT NULL,
  `from_version` VARCHAR(30) NOT NULL DEFAULT '',
  `to_version` VARCHAR(30) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=下载中 1=已下载 2=应用成功 3=应用失败',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_instance` (`instance_id`),
  KEY `idx_update` (`update_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实例更新记录';

-- 远程模块
CREATE TABLE IF NOT EXISTS `remote_modules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_code` VARCHAR(64) NOT NULL COMMENT '模块唯一编码',
  `product_id` INT UNSIGNED NOT NULL COMMENT '归属产品',
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `module_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=PHP代码型 2=HTTP转发型 3=数据查询型',
  `code` LONGTEXT COMMENT '模块PHP代码(module_type=1时有效)',
  `upstream_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '转发目标URL(module_type=2时有效)',
  `sql_template` TEXT COMMENT '查询SQL模板(module_type=3时有效, 仅SELECT)',
  `version` VARCHAR(30) NOT NULL DEFAULT '1.0.0',
  `params_schema` TEXT COMMENT '参数校验规则(JSON Schema 子集)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_code` (`module_code`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程模块';

-- 远程模块调用日志
CREATE TABLE IF NOT EXISTS `module_invoke_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_id` INT UNSIGNED NOT NULL,
  `instance_id` INT UNSIGNED DEFAULT NULL,
  `params` TEXT COMMENT '调用参数(JSON)',
  `result` LONGTEXT COMMENT '返回结果(JSON)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=成功 0=失败',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `cost_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='远程模块调用日志';

-- 授权验证日志
CREATE TABLE IF NOT EXISTS `verify_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `license_id` INT UNSIGNED DEFAULT NULL,
  `instance_id` INT UNSIGNED DEFAULT NULL,
  `domain` VARCHAR(191) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `license_key_masked` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '授权码掩码(前6后4)',
  `result` TINYINT NOT NULL COMMENT '1=通过 0=失败',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '失败原因',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_product_result` (`product_id`,`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权验证日志';

-- 系统设置
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(64) NOT NULL,
  `svalue` TEXT,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- 后台操作日志
CREATE TABLE IF NOT EXISTS `operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `detail` TEXT,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台操作日志';

-- 系统级 OTA 升级包（授权系统自身升级）
CREATE TABLE IF NOT EXISTS `system_updates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(30) NOT NULL COMMENT '目标系统版本',
  `package_path` VARCHAR(255) NOT NULL COMMENT '升级包相对 storage 的路径',
  `package_md5` VARCHAR(64) NOT NULL,
  `package_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `min_version` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '允许升级的最低版本',
  `changelog` TEXT COMMENT '更新日志',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=待发布 1=已发布 2=已应用 3=已撤回 4=应用失败',
  `applied_at` DATETIME DEFAULT NULL COMMENT '应用成功时间',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统级OTA升级包';

-- 商城买家（V1.1 商城化）
CREATE TABLE IF NOT EXISTS `buyers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL COMMENT '登录账号(邮箱)',
  `email` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `password_hash` VARCHAR(255) NOT NULL COMMENT '密码哈希(bcrypt)',
  `nickname` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '昵称/客户名',
  `contact` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '联系QQ/微信/电话',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=正常 0=禁用',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城买家(客户)';

-- 商城订单（V1.1 商城化）
CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '订单号',
  `buyer_id` INT UNSIGNED NOT NULL COMMENT '买家ID',
  `product_id` INT UNSIGNED NOT NULL COMMENT '产品ID',
  `product_code` VARCHAR(50) NOT NULL COMMENT '冗余产品编码',
  `product_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '冗余产品名',
  `qty` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '数量',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
  `duration_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '授权时长(天), 0=永久',
  `pay_channel` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT '支付渠道: alipay/wxpay/epay/manual(人工)',
  `pay_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=待支付 1=已支付 2=已取消 3=已关闭',
  `paid_at` DATETIME DEFAULT NULL,
  `pay_trade_no` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '第三方流水号',
  `license_id` INT UNSIGNED DEFAULT NULL COMMENT '发放的授权码ID',
  `customer_note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '客户备注(绑定域名/IP等)',
  `ship_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0=未发货 1=已自动发码 2=已人工发货',
  `ship_note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '发货备注',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_buyer` (`buyer_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_pay_status` (`pay_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城订单';

-- 技能包（V1.2 Skill 技能包体系）
CREATE TABLE IF NOT EXISTS `skills` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_code` VARCHAR(64) NOT NULL COMMENT '技能唯一编码',
  `name` VARCHAR(100) NOT NULL COMMENT '技能名称',
  `description` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '技能描述',
  `icon` VARCHAR(20) NOT NULL DEFAULT '🧩' COMMENT '展示图标(emoji)',
  `product_id` INT UNSIGNED NOT NULL COMMENT '归属产品',
  `access_model` TINYINT NOT NULL DEFAULT 0 COMMENT '授权模型 0=产品下全部授权实例可用 1=按授权码授权',
  `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用(停用后实例不可调用)',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_code` (`skill_code`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能包';

-- 技能函数（V1.2）
CREATE TABLE IF NOT EXISTS `skill_functions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` INT UNSIGNED NOT NULL COMMENT '所属技能',
  `function_code` VARCHAR(64) NOT NULL COMMENT '函数编码(技能内唯一)',
  `name` VARCHAR(100) NOT NULL COMMENT '函数名称',
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `func_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=PHP代码型 2=HTTP转发型 3=数据查询型',
  `code` LONGTEXT COMMENT 'PHP代码(func_type=1)',
  `upstream_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '转发目标URL(func_type=2)',
  `sql_template` TEXT COMMENT '查询SQL模板(func_type=3, 仅SELECT)',
  `params_schema` TEXT COMMENT '参数校验规则(JSON Schema 子集)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_func` (`skill_id`,`function_code`),
  KEY `idx_skill` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能函数';

-- 技能授权（V1.2，按授权码授权模型）
CREATE TABLE IF NOT EXISTS `skill_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` INT UNSIGNED NOT NULL,
  `license_id` INT UNSIGNED NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已授权 0=已撤销',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_license` (`skill_id`,`license_id`),
  KEY `idx_license` (`license_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能授权(授权码维度)';

-- 技能调用日志（V1.2）
CREATE TABLE IF NOT EXISTS `skill_invoke_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` INT UNSIGNED NOT NULL,
  `function_id` INT UNSIGNED NOT NULL,
  `license_id` INT UNSIGNED DEFAULT NULL,
  `instance_id` INT UNSIGNED DEFAULT NULL,
  `params` TEXT COMMENT '调用参数(JSON)',
  `result` LONGTEXT COMMENT '返回结果(JSON)',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=成功 0=失败',
  `error` VARCHAR(500) NOT NULL DEFAULT '',
  `cost_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_skill` (`skill_id`),
  KEY `idx_function` (`function_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能调用日志';

SET FOREIGN_KEY_CHECKS = 1;
