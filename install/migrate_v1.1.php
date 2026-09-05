<?php
/**
 * DCAI 授权系统 V1.1 商城化数据库迁移 (幂等，可重复执行；MySQL 5.6 兼容)
 * 用法: php install/migrate_v1.1.php
 * 新增：产品售卖字段、license 永久授权(expire_at NULL)+销售来源、buyers 买家表、orders 订单表
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$db = dcai_db();
$now = dcai_now();

function col_exists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$col'");
    return (int)$stmt->fetchColumn() > 0;
}

$pdo = $db->pdo();
$ok = 0;

// ---------- 1. products 售卖字段 ----------
$adds = [
    "ALTER TABLE `products` ADD COLUMN `for_sale` TINYINT NOT NULL DEFAULT 0 COMMENT '1=上架销售(商城可见可买) 0=仅内部' AFTER `status`",
    "ALTER TABLE `products` ADD COLUMN `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价(元), 0=不售卖' AFTER `for_sale`",
    "ALTER TABLE `products` ADD COLUMN `price_unit` VARCHAR(20) NOT NULL DEFAULT 'year' COMMENT '计费单位 month/quarter/half_year/year/perpetual' AFTER `sale_price`",
    "ALTER TABLE `products` ADD COLUMN `sale_icon` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '商城展示图标URL' AFTER `price_unit`",
    "ALTER TABLE `products` ADD COLUMN `sale_intro` TEXT COMMENT '商城详情(富文本)' AFTER `sale_icon`",
];
foreach ($adds as $sql) {
    if (preg_match('/ADD COLUMN `(\w+)`/', $sql, $m) && !col_exists($pdo, 'products', $m[1])) {
        try { $pdo->exec($sql); echo "products + {$m[1]}\n"; $ok++; }
        catch (Throwable $e) { echo "products {$m[1]} ERR: {$e->getMessage()}\n"; }
    }
}

// ---------- 2. licenses 销售来源 + 永久授权 ----------
foreach ([
    "ALTER TABLE `licenses` ADD COLUMN `source` TINYINT NOT NULL DEFAULT 0 COMMENT '0=后台手动 1=商城自动 2=人工发货' AFTER `status`",
    "ALTER TABLE `licenses` ADD COLUMN `buyer_id` INT UNSIGNED DEFAULT NULL COMMENT '购买者(买家)ID' AFTER `source`",
    "ALTER TABLE `licenses` ADD COLUMN `order_id` INT UNSIGNED DEFAULT NULL COMMENT '来源订单ID' AFTER `buyer_id`",
] as $sql) {
    if (preg_match('/ADD COLUMN `(\w+)`/', $sql, $m) && !col_exists($pdo, 'licenses', $m[1])) {
        try { $pdo->exec($sql); echo "licenses + {$m[1]}\n"; $ok++; }
        catch (Throwable $e) { echo "licenses {$m[1]} ERR: {$e->getMessage()}\n"; }
    }
}
// expire_at 允许 NULL（永久授权）
try {
    $pdo->exec("ALTER TABLE `licenses` MODIFY COLUMN `expire_at` DATETIME NULL COMMENT '授权到期时间, NULL=永久'");
    echo "licenses expire_at -> NULL allowed\n"; $ok++;
} catch (Throwable $e) {
    echo "licenses expire_at MODIFY ERR: {$e->getMessage()}\n";
}

// ---------- 3. buyers 买家表 ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `buyers` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城买家(客户)'");
echo "buyers table ready\n"; $ok++;

// ---------- 4. orders 订单表 ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商城订单'");
echo "orders table ready\n"; $ok++;

echo "\nMIGRATE DONE. changes=$ok\n";