<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=dcai_auth;charset=utf8mb4', 'root', 'a1258923126.');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query(
    "SELECT i.instance_id, i.domain, i.ip, i.version, i.status, i.last_heartbeat_at, p.product_code
     FROM instances i JOIN products p ON p.id = i.product_id
     WHERE p.product_code = 'ai_novel'"
)->fetchAll(PDO::FETCH_ASSOC);
echo "=== 实例列表 ===\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$logs = $pdo->query(
    "SELECT vl.domain, vl.ip, vl.result, vl.reason, vl.created_at
     FROM verify_logs vl JOIN products p ON p.id = vl.product_id
     WHERE p.product_code = 'ai_novel' ORDER BY vl.id DESC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== 最近验证日志 ===\n" . json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
