-- DCAI 授权系统 V1.1 商城化升级脚本 (MySQL 5.6 兼容)
-- 用途：将系统从内部授权中台升级为"对外授权商城"
-- 新增：买家(buyers)、订单(orders)、产品售卖设置、license 支持永久(expire_at NULL)、付费源标记

SET NAMES utf8mb4;

-- 1. products 增加售卖字段（对外商城）
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `for_sale` TINYINT NOT NULL DEFAULT 0 COMMENT '1=上架销售(商城可见可买) 0=仅内部' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价(元), 0=不售卖' AFTER `for_sale`,
  ADD COLUMN IF NOT EXISTS `price_unit` VARCHAR(20) NOT NULL DEFAULT 'year' COMMENT '计费单位 month/quarter/half_year/year/perpetual' AFTER `sale_price`,
  ADD COLUMN IF NOT EXISTS `sale_icon` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '商城展示图标URL' AFTER `price_unit`,
  ADD COLUMN IF NOT EXISTS `sale_intro` TEXT COMMENT '商城详情(富文本)' AFTER `sale_icon`;

-- 2. licenses 支持永久授权（expire_at 允许 NULL）与销售来源标记
ALTER TABLE `licenses`
  ADD COLUMN IF NOT EXISTS `source` TINYINT NOT NULL DEFAULT 0 COMMENT '0=后台手动 1=商城自动 2=人工发货' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `buyer_id` INT UNSIGNED DEFAULT NULL COMMENT '购买者(买家)ID, 商城订单关联' AFTER `source`,
  ADD COLUMN IF NOT EXISTS `order_id` INT UNSIGNED DEFAULT NULL COMMENT '来源订单ID' AFTER `buyer_id`,
  MODIFY COLUMN `expire_at` DATETIME NULL COMMENT '授权到期时间, NULL=永久';

-- 3. 买家表（商城注册用户）
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

-- 4. 订单表
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

-- 5. 后台系统设置键位 (用于通知/商城开关，此处仅作注释说明，运行时代码写入)
