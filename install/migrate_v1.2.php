<?php
/**
 * DCAI 授权系统 V1.2 技能包（Skill）数据库迁移 (幂等，可重复执行；MySQL 5.6 兼容)
 * 用法: php install/migrate_v1.2.php
 * 新增：skills 技能包、skill_functions 技能函数、skill_grants 技能授权、skill_invoke_logs 技能调用日志
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$db = dcai_db();
$pdo = $db->pdo();

function dcai_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'");
    return (int)$stmt->fetchColumn() > 0;
}

$ok = 0;

// ---------- 1. skills 技能包 ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `skills` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能包'");
echo "skills table ready\n"; $ok++;

// ---------- 2. skill_functions 技能函数 ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `skill_functions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能函数'");
echo "skill_functions table ready\n"; $ok++;

// ---------- 3. skill_grants 技能授权(按授权码授权) ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `skill_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skill_id` INT UNSIGNED NOT NULL,
  `license_id` INT UNSIGNED NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1=已授权 0=已撤销',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_license` (`skill_id`,`license_id`),
  KEY `idx_license` (`license_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能授权(授权码维度)'");
echo "skill_grants table ready\n"; $ok++;

// ---------- 4. skill_invoke_logs 技能调用日志 ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS `skill_invoke_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技能调用日志'");
echo "skill_invoke_logs table ready\n"; $ok++;

echo "\nMIGRATE V1.2 DONE. changes=$ok\n";