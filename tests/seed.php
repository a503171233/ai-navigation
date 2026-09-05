<?php
/**
 * 测试数据初始化（演示/联调用）
 * 用法: php tests/seed.php
 */
require_once dirname(__DIR__) . '/core/Bootstrap.php';

$db = dcai_db();
$now = dcai_now();

// 产品
$product = $db->queryOne('SELECT * FROM products WHERE product_code = ?', ['shop_v2']);
if (!$product) {
    $pid = $db->insert('products', [
        'product_code'    => 'shop_v2',
        'name'            => '商城系统 V2',
        'description'     => '多商户商城系统（演示数据）',
        'current_version' => '1.0.0',
        'enforce_auth'    => 1,
        'fail_open'       => 1,
        'verify_ttl'      => 3600,
        'status'          => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
} else {
    $pid = (int)$product['id'];
    $db->update('products', ['enforce_auth' => 1, 'current_version' => '1.0.0', 'updated_at' => $now], 'id = ?', [$pid]);
}
echo "产品ID: $pid\n";

// 授权码（绑定 localhost 与 127.0.0.1）
$lic = $db->queryOne('SELECT * FROM licenses WHERE product_id = ? LIMIT 1', [$pid]);
if (!$lic) {
    [$ok, $lid] = DCAI_LicenseService::create([
        'product_id'      => $pid,
        'customer_name'   => '演示客户',
        'customer_email'  => 'demo@example.com',
        'allowed_domains' => "127.0.0.1\nlocalhost",
        'allowed_ips'     => "127.0.0.1\n::1",
        'max_instances'   => 5,
        'expire_at'       => date('Y-m-d H:i:s', strtotime('+365 days')),
        'remark'          => '本地联调授权码',
        'status'          => 1,
    ]);
    echo $ok ? "授权码ID: $lid\n" : "创建失败: $lid\n";
    $license = $db->queryOne('SELECT * FROM licenses WHERE id = ?', [$lid]);
} else {
    $license = $lic;
}
echo "授权码: {$license['license_key']}\n";

// 远程模块（PHP 代码型示例）
$mod = $db->queryOne('SELECT * FROM remote_modules WHERE module_code = ?', ['demo_hello']);
if (!$mod) {
    $db->insert('remote_modules', [
        'module_code'  => 'demo_hello',
        'product_id'   => $pid,
        'name'         => '演示模块',
        'description'  => '返回问候语与当前时间',
        'module_type'  => 1,
        'code'         => '$name = $params["name"] ?? "世界";
return ["hello" => "你好, " . $name . "!", "time" => date("Y-m-d H:i:s")];',
        'upstream_url' => '',
        'sql_template' => '',
        'version'      => '1.0.0',
        'params_schema' => '{"type":"object","properties":{"name":{"type":"string","maxLength":20}}}',
        'status'       => 1,
        'created_by'   => 1,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    echo "模块 demo_hello 已创建\n";
}

// 弹窗
$pop = $db->queryOne('SELECT * FROM popups WHERE product_id = ? AND title = ?', [$pid, '系统维护通知']);
if (!$pop) {
    $db->insert('popups', [
        'product_id'          => $pid,
        'title'               => '系统维护通知',
        'content'             => '<p>系统将于周末 2:00-4:00 进行维护升级，请提前保存数据。</p>',
        'popup_type'          => 2,
        'target_type'         => 0,
        'target_instance_ids' => '[]',
        'start_at'            => null,
        'end_at'              => null,
        'max_show_per_instance' => 0,
        'status'              => 1,
        'created_by'          => 1,
        'created_at'          => $now,
        'updated_at'          => $now,
    ]);
    echo "弹窗已创建\n";
}

// 技能（Skill）：演示用，核心逻辑托管在授权系统，被授权站点仅调壳
$skill = $db->queryOne('SELECT * FROM skills WHERE skill_code = ?', ['demo_greet']);
if (!$skill) {
    $sid = $db->insert('skills', [
        'skill_code'   => 'demo_greet',
        'name'         => '演示问候技能',
        'description'  => '返回暖心问候与当前时间（核心逻辑在授权系统）',
        'icon'         => '🙋',
        'product_id'   => $pid,
        'access_model' => 0,
        'sort_order'   => 0,
        'status'       => 1,
        'created_by'   => 1,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $db->insert('skill_functions', [
        'skill_id'       => $sid,
        'function_code'  => 'greet',
        'name'           => '问候',
        'description'    => '返回问候语与当前时间',
        'func_type'      => 1,
        'code'           => '$name = $params["name"] ?? "朋友";
return ["hello" => "你好, " . $name . "！愿你今天有个好心情 😊", "time" => date("Y-m-d H:i:s")];',
        'upstream_url'   => '',
        'sql_template'   => '',
        'params_schema'  => '{"type":"object","properties":{"name":{"type":"string","maxLength":20}}}',
        'status'         => 1,
        'created_at'     => $now,
        'updated_at'     => $now,
    ]);
    echo "技能 demo_greet 已创建\n";
}

// 授权模型=按授权码 的演示技能（验证 skill_grants）
$skill2 = $db->queryOne('SELECT * FROM skills WHERE skill_code = ?', ['demo_license_skill']);
if (!$skill2) {
    $sid2 = $db->insert('skills', [
        'skill_code'   => 'demo_license_skill',
        'name'         => '按码授权技能',
        'description'  => '需按授权码逐一授予（access_model=1）',
        'icon'         => '🔒',
        'product_id'   => $pid,
        'access_model' => 1,
        'sort_order'   => 0,
        'status'       => 1,
        'created_by'   => 1,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $db->insert('skill_functions', [
        'skill_id'     => $sid2,
        'function_code'=> 'secret',
        'name'         => '机密接口',
        'description'  => '需授权方可调用',
        'func_type'    => 1,
        'code'         => 'return ["secret" => "授权码专属数据: " . ($params["k"] ?? "")];',
        'upstream_url' => '',
        'sql_template' => '',
        'params_schema'=> '',
        'status'       => 1,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    echo "技能 demo_license_skill 已创建\n";
}

echo "数据初始化完成\n";
