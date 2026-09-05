<?php
/**
 * DCAI 客户端 SDK 主类
 *
 * 集成方式（被授权程序入口）：
 *   require __DIR__ . '/sdk/dcai_client.php';
 *   $dcai = new DCAI_Client();
 *   $dcai->guard(function () { http_response_code(403); exit('程序未授权'); });
 *   register_shutdown_function(function () use ($dcai) { $dcai->runHooks(); });
 *
 * 通信：HTTPS/HTTP + JSON，HMAC-SHA256 签名
 *  - 未注册阶段使用 app_secret 签名（verify / register）
 *  - 已注册阶段使用 instance_token 签名
 */

require_once __DIR__ . '/dcai_cache.php';

final class DCAI_Client
{
    private array $config;
    private DCAI_Cache $cache;
    private array $commandHandlers = [];
    private array $verifyResult = [];

    public function __construct(?array $config = null)
    {
        $configFile = __DIR__ . '/dcai_config.php';
        if ($config === null && is_file($configFile)) {
            $config = require $configFile;
        }
        $config = is_array($config) ? $config : [];
        $this->config = array_merge([
            'server_url'    => '',
            'product_code'  => '',
            'app_secret'    => '',
            'license_key'   => '',
            'enabled'       => true,
            'fail_open'     => true,
            'cache_dir'     => __DIR__ . '/cache/',
            'rsa_public_key' => '',
            'http_timeout'  => 5,
            'ssl_verify'    => true,
            'ca_bundle'     => '',
            'heartbeat_interval' => 60,
            'update_dir'    => __DIR__ . '/update_cache/',
            'app_root'      => dirname(__DIR__),
            'app_version'   => '1.0.0',
        ], $config);
        $this->cache = new DCAI_Cache($this->config['cache_dir']);
    }

    public function config(): array
    {
        return $this->config;
    }

    // ------------------------------------------------------------
    // 底层 HTTP 请求
    // ------------------------------------------------------------

    /**
     * 发送签名请求
     * @param string $path 形如 auth/verify
     * @param array $body
     * @param string $secret 签名密钥（app_secret 或 instance_token）
     * @param int $timeout 可选：覆盖超时(秒)，0 使用全局配置
     * @return array{ok:bool,code:int,msg:string,data:mixed,http:int,error:string}
     */
    private function signedRequest(string $path, array $body, string $secret, int $timeout = 0): array
    {
        $base = rtrim($this->config['server_url'], '/') . '/';
        $url = $base . $path;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(8));
        $sign = $this->sign($secret, $timestamp, $nonce, $json);

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-Instance-Id: ' . ($this->getInstanceId() ?: ''),
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Sign: ' . $sign,
        ];

        $timeoutSec = $timeout > 0 ? $timeout : (int)$this->config['http_timeout'];
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSec > 0 ? $timeoutSec : 1,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec > 0 ? $timeoutSec : 1,
        ];
        // TLS 证书校验：默认开启，仅在显式配置 ssl_verify=false 时关闭（自签证书场景）
        if (!empty($this->config['ssl_verify'])) {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = true;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = 2;
            if (!empty($this->config['ca_bundle'])) {
                $curlOpts[CURLOPT_CAINFO] = $this->config['ca_bundle'];
            }
        } else {
            $curlOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOpts[CURLOPT_SSL_VERIFYHOST] = false;
        }
        curl_setopt_array($ch, $curlOpts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $errMsg = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'code' => -1, 'msg' => '网络错误: ' . $errMsg, 'data' => null, 'http' => $http, 'error' => $errMsg];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'code' => -2, 'msg' => '响应解析失败', 'data' => null, 'http' => $http, 'error' => $raw];
        }
        return [
            'ok'    => (int)($decoded['code'] ?? -1) === 0,
            'code'  => (int)($decoded['code'] ?? -1),
            'msg'   => (string)($decoded['msg'] ?? ''),
            'data'  => $decoded['data'] ?? null,
            'http'  => $http,
            'error' => '',
        ];
    }

    private function sign(string $secret, int $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
    }

    // ------------------------------------------------------------
    // ① 三合一授权验证
    // ------------------------------------------------------------

    public function verify(): bool
    {
        if (!$this->config['enabled']) {
            return true; // 授权开关关闭 = 默认放行
        }
        // 服务端下发的产品级策略（verify 成功后缓存，覆盖本地默认值）
        $policy = $this->serverPolicy();
        if ($policy !== null && empty($policy['enforce_auth'])) {
            return true; // 服务端声明该产品未开启授权管控 = 放行
        }

        // 0.5 离线激活状态：已导入有效激活文件（验签+机器匹配通过）→ 直接视为已授权
        //    即使完全断网也能通过 guard；到期校验在 applyOfflineActivation 时已做，此处按缓存字段复核
        $offline = $this->cache->get('offline_activation');
        if (is_array($offline) && !empty($offline['file'])) {
            if (empty($offline['expire_at']) || strtotime((string)$offline['expire_at']) > time()) {
                $this->verifyResult = $offline['file'] + ['offline' => true];
                return true;
            }
            // 已到期：清除离线态，走正常在线验证
            $this->cache->delete('offline_activation');
        }

        // 1. 本地缓存令牌：未过期且 RSA 验签通过 → 已授权
        $cached = $this->cache->get('verification_token');
        if (is_array($cached) && !empty($cached['token'])) {
            if (empty($cached['expire_at']) || strtotime((string)$cached['expire_at']) > time()) {
                $payload = $this->verifyToken($cached['token']);
                if ($payload !== null) {
                    $this->verifyResult = $cached + ['payload' => $payload];
                    return true;
                }
            }
            $this->cache->delete('verification_token');
        }

        // 2. 调用验证接口（app_secret 签名）
        $resp = $this->signedRequest('auth/verify', [
            'product_code'  => $this->config['product_code'],
            'license_key'   => $this->config['license_key'],
            'domain'        => $this->currentDomain(),
            'ip'            => $this->currentIp(),
            'client_version' => $this->config['app_version'],
            'instance_id'   => $this->getInstanceId() ?: '',
            'machine_code'  => $this->currentMachineCode(),
            'machine_name'  => $this->machineName(),
        ], $this->config['app_secret']);

        if ($resp['ok'] && !empty($resp['data']['verified'])) {
            // 缓存服务端策略：fail_open / enforce_auth（老版本服务端不下发时回退本地配置）
            $this->cache->set('server_policy', [
                'fail_open'    => isset($resp['data']['fail_open']) ? (bool)$resp['data']['fail_open'] : (bool)$this->config['fail_open'],
                'enforce_auth' => isset($resp['data']['enforce_auth']) ? (bool)$resp['data']['enforce_auth'] : true,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], 0);
            $this->cache->set('verification_token', [
                'token'     => $resp['data']['token'],
                'expire_at' => $resp['data']['expire_at'],
                'verified_at' => date('Y-m-d H:i:s'),
            ], $this->remainingSeconds($resp['data']['expire_at'] ?? ''));
            $this->verifyResult = $resp['data'];
            return true;
        }

        // 3. 网络异常：按 fail_open 策略处理（服务端策略优先，其次本地配置）
        if ($resp['code'] === -1 || $resp['code'] === -2) {
            $failOpen = $policy !== null ? $policy['fail_open'] : (bool)$this->config['fail_open'];
            $this->logWarning('授权服务器不可达，fail_open=' . ($failOpen ? 'true' : 'false'));
            return $failOpen;
        }

        // 4. 明确被拒绝（禁用/过期/吊销）
        return false;
    }

    /**
     * 读取服务端下发的产品级策略（verify 成功后缓存）
     * @return array|null ['fail_open'=>bool,'enforce_auth'=>bool] 或 null（尚未获取）
     */
    private function serverPolicy(): ?array
    {
        $p = $this->cache->get('server_policy');
        return is_array($p) ? $p : null;
    }

    public function getVerifiedInfo(): ?array
    {
        $info = $this->verifyResult;
        if (!$info) {
            $cached = $this->cache->get('verification_token');
            $info = is_array($cached) ? $cached : null;
        }
        if ($info && isset($info['payload'])) {
            $info['product_code'] = $info['payload']['product_code'] ?? '';
            $info['domain'] = $info['payload']['domain'] ?? '';
            $info['ip'] = $info['payload']['ip'] ?? '';
        }
        return $info ?: null;
    }

    /**
     * 读取试用状态（服务端 verify 响应携带；仅试用授权码返回非空）
     * 优先使用最近一次在线 verify 结果；缓存令牌不含 trial 字段时返回 null
     * @return array|null ['is_trial'=>bool,'trial_days'=>int,'trial_expire_at'=>?string,'trial_remaining_days'=>int] 或 null
     */
    public function getTrialStatus(): ?array
    {
        $info = $this->verifyResult;
        if (empty($info['trial'])) {
            return null;
        }
        $trial = $info['trial'];
        return is_array($trial) ? $trial : null;
    }

    /**
     * 读取机器码绑定状态（verify 响应携带）
     * @return array|null ['code'=>string,'limit'=>int,'bound'=>bool] 或 null
     */
    public function getMachineStatus(): ?array
    {
        $info = $this->getVerifiedInfo();
        if (!is_array($info) || empty($info['machine'])) {
            return null;
        }
        $machine = $info['machine'];
        return is_array($machine) ? $machine : null;
    }

    /**
     * RSA 公钥验签验证令牌
     */
    public function verifyToken(string $token): ?array
    {
        $pub = $this->config['rsa_public_key'];
        if ($pub === '') {
            return null;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sigB64] = $parts;
        $pkey = @openssl_pkey_get_public($pub);
        if (!$pkey) {
            return null;
        }
        $ok = @openssl_verify($encoded, base64_decode(strtr($sigB64, '-_', '+/')), $pkey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        return is_array($payload) ? $payload : null;
    }

    // ------------------------------------------------------------
    // ② 实例注册
    // ------------------------------------------------------------

    public function registerInstance(): array
    {
        $cached = $this->cache->get('instance');
        if (is_array($cached) && !empty($cached['instance_id']) && !empty($cached['instance_token'])) {
            return $cached;
        }
        $resp = $this->signedRequest('instance/register', [
            'product_code' => $this->config['product_code'],
            'license_key'  => $this->config['license_key'],
            'domain'       => $this->currentDomain(),
            'ip'           => $this->currentIp(),
            'version'      => $this->config['app_version'],
            'server_info'  => $this->serverInfo(),
            'db_info'      => $this->dbInfo(),
            'machine_code' => $this->currentMachineCode(),
            'machine_name' => $this->machineName(),
        ], $this->config['app_secret']);
        if ($resp['ok'] && !empty($resp['data']['instance_id'])) {
            $data = $resp['data'];
            $this->cache->set('instance', [
                'instance_id'    => $data['instance_id'],
                'instance_token' => $data['instance_token'],
            ], 0);
            return $data;
        }
        return ['error' => $resp['msg'], 'code' => $resp['code']];
    }

    private function getInstanceId(): ?string
    {
        $cached = $this->cache->get('instance');
        return is_array($cached) ? ($cached['instance_id'] ?? null) : null;
    }

    private function getInstanceToken(): ?string
    {
        $cached = $this->cache->get('instance');
        return is_array($cached) ? ($cached['instance_token'] ?? null) : null;
    }

    private function ensureInstance(): bool
    {
        $token = $this->getInstanceToken();
        if ($token !== null) {
            return true;
        }
        // 注册失败退避：60 秒内不重复尝试，避免服务器抖动时每次请求都叠加注册网络调用
        $lastFail = (int)$this->cache->get('register_fail_at', 0);
        if ($lastFail > 0 && time() - $lastFail < 60) {
            return false;
        }
        $res = $this->registerInstance();
        if (isset($res['error'])) {
            $this->cache->set('register_fail_at', time(), 0);
            return false;
        }
        $this->cache->delete('register_fail_at');
        return true;
    }

    /**
     * 静默确保实例已注册：可用于非关键路径（如技能列表）避免副作用抛错
     */
    private function ensureInstanceSilent(): bool
    {
        try {
            return $this->ensureInstance();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------
    // ③ 心跳
    // ------------------------------------------------------------

    public function heartbeat(): array
    {
        if (!$this->ensureInstance()) {
            return ['error' => '实例未注册'];
        }
        $resp = $this->signedRequest('instance/heartbeat', [
            'version'      => $this->config['app_version'],
            'server_info'  => $this->serverInfo(),
            'db_info'      => $this->dbInfo(),
        ], $this->getInstanceToken());
        if ($resp['ok']) {
            $this->cache->set('last_heartbeat', time(), 0);
        }
        return $resp;
    }

    // ------------------------------------------------------------
    // ④ 命令轮询与执行
    // ------------------------------------------------------------

    public function onCommand(string $type, callable $handler): void
    {
        $this->commandHandlers[$type] = $handler;
    }

    public function pollCommands(): array
    {
        if (!$this->ensureInstance()) {
            return ['error' => '实例未注册'];
        }
        $resp = $this->signedRequest('command/poll', [], $this->getInstanceToken());
        if (!$resp['ok']) {
            return $resp;
        }
        $commands = $resp['data']['commands'] ?? [];
        foreach ($commands as $cmd) {
            $this->executeCommand($cmd);
        }
        return $resp;
    }

    private function executeCommand(array $cmd): void
    {
        $id = (int)($cmd['id'] ?? 0);
        $type = (string)($cmd['command_type'] ?? '');
        $payload = is_array($cmd['payload'] ?? null) ? $cmd['payload'] : [];

        try {
            $result = $this->dispatchCommand($type, $payload);
            $this->signedRequest('command/report', [
                'command_id' => $id,
                'status'     => 2,
                'result'     => ['ok' => true, 'detail' => $result],
            ], $this->getInstanceToken());
        } catch (Throwable $e) {
            $this->signedRequest('command/report', [
                'command_id' => $id,
                'status'     => 3,
                'result'     => ['ok' => false, 'error' => $e->getMessage()],
            ], $this->getInstanceToken());
        }
    }

    private function dispatchCommand(string $type, array $payload)
    {
        // 自定义回调优先
        if (isset($this->commandHandlers[$type])) {
            return call_user_func($this->commandHandlers[$type], $payload);
        }
        switch ($type) {
            case 'disable':
                $this->cache->set('revoked', time(), 0);
                return '程序已禁用';
            case 'enable':
                $this->cache->delete('revoked');
                return '程序已启用';
            case 'config_push':
                if (isset($payload['config']) && is_array($payload['config'])) {
                    $this->writeConfig($payload['config']);
                    return '配置已推送';
                }
                return '配置为空';
            case 'maintenance_on':
                $this->cache->set('maintenance', $payload, 86400);
                return '已进入维护模式';
            case 'maintenance_off':
                $this->cache->delete('maintenance');
                return '已退出维护模式';
            case 'reboot':
                $this->cache->set('reboot_requested', time(), 0);
                return '重启指令已接收';
            case 'update':
                if (!empty($payload['target_version'])) {
                    $update = $this->checkUpdate($payload['target_version']);
                    if ($update) {
                        $this->applyUpdate($update);
                        return '更新完成';
                    }
                    return '未找到目标版本更新';
                }
                $update = $this->checkUpdate();
                if ($update) {
                    $this->applyUpdate($update);
                    return '更新完成';
                }
                return '当前已是最新版本';
            default:
                return '未注册的命令类型: ' . $type;
        }
    }

    private function writeConfig(array $config): void
    {
        $file = $this->config['app_root'] . '/config_override.php';
        $content = '<?php return ' . var_export($config, true) . ';';
        @file_put_contents($file, $content, LOCK_EX);
    }

    // ------------------------------------------------------------
    // ⑤ 弹窗
    // ------------------------------------------------------------

    public function getPopups(): array
    {
        if (!$this->ensureInstance()) {
            return ['error' => '实例未注册'];
        }
        return $this->signedRequest('popup/list', [], $this->getInstanceToken());
    }

    public function reportPopupShown(int $popupId): bool
    {
        if (!$this->ensureInstance()) {
            return false;
        }
        $resp = $this->signedRequest('popup/report', ['popup_id' => $popupId], $this->getInstanceToken());
        return $resp['ok'];
    }

    // ------------------------------------------------------------
    // ⑥ 更新
    // ------------------------------------------------------------

    public function checkUpdate(?string $targetVersion = null): ?array
    {
        if (!$this->ensureInstance()) {
            return null;
        }
        $body = ['current_version' => $targetVersion ?: $this->config['app_version']];
        $resp = $this->signedRequest('update/check', $body, $this->getInstanceToken());
        if (!$resp['ok'] || empty($resp['data']['has_update'])) {
            return null;
        }
        return $resp['data']['update'];
    }

    /**
     * 应用更新（下载 → 校验 MD5 → 解压替换 → 上报）
     */
    public function applyUpdate(array $update): bool
    {
        if (!class_exists('DCAI_Updater', false)) {
            require_once __DIR__ . '/dcai_updater.php';
        }
        $updater = new DCAI_Updater($this->config);
        $ok = $updater->apply($update);
        if ($this->ensureInstance()) {
            $this->signedRequest('update/report', [
                'target_version' => $update['version'],
                'status'         => $ok ? 2 : 3,
                'error'          => $ok ? '' : $updater->lastError(),
            ], $this->getInstanceToken());
        }
        return $ok;
    }

    // ------------------------------------------------------------
    // ⑧ 远程模块
    // ------------------------------------------------------------

    public function callModule(string $moduleCode, array $params = []): mixed
    {
        if (!$this->ensureInstance()) {
            throw new RuntimeException('实例未注册，无法调用远程模块');
        }
        $resp = $this->signedRequest('module/invoke', [
            'module_code' => $moduleCode,
            'params'      => $params,
        ], $this->getInstanceToken());
        if (!$resp['ok']) {
            throw new RuntimeException('模块调用失败(' . $resp['code'] . '): ' . $resp['msg']);
        }
        return $resp['data']['result'] ?? null;
    }

    // ------------------------------------------------------------
    // ⑨ 技能（Skill）能力：被授权站点作为"壳"，远程调用授权系统托管的技能函数
    // ------------------------------------------------------------

    /**
     * 获取当前实例可见且已授权的技能列表（含其可用函数）
     * @return array 技能列表；未启用授权或实例未注册时返回空数组（不抛出异常，保持壳可降级）
     */
    public function getSkills(): array
    {
        if (!$this->config['enabled'] || !$this->ensureInstanceSilent()) {
            return [];
        }
        $resp = $this->signedRequest('skill/list', [], $this->getInstanceToken());
        if (!$resp['ok']) {
            $this->logWarning('获取技能列表失败(' . $resp['code'] . '): ' . $resp['msg']);
            return [];
        }
        return $resp['data']['skills'] ?? [];
    }

    /**
     * 调用授权系统托管的技能函数（核心逻辑在授权系统，本地只留壳）
     * @param string $skillCode     技能编码
     * @param string $functionCode  函数编码（技能内唯一）
     * @param array $params         业务参数
     * @param int $timeout          可选：调用超时(秒)，默认沿用全局 http_timeout；0 表示不覆盖
     * @return mixed 执行结果
     * @throws RuntimeException 技能不存在/无权限/参数或执行异常时抛出
     *
     * 兜底机制示例（壳内可选）：
     *   try { $r = $dcai->callSkill('core','feeCalc',['a'=>1]); }
     *   catch (Throwable $e) { $r = $localFallback(...); } // 降级到本地兜底逻辑
     */
    public function callSkill(string $skillCode, string $functionCode, array $params = [], int $timeout = 0): mixed
    {
        if (!$this->config['enabled']) {
            throw new RuntimeException('授权未启用，无法调用技能');
        }
        if (!$this->ensureInstance()) {
            throw new RuntimeException('实例未注册，无法调用技能');
        }
        $resp = $this->signedRequest('skill/invoke', [
            'skill_code'    => $skillCode,
            'function_code' => $functionCode,
            'params'        => $params,
        ], $this->getInstanceToken(), $timeout);
        if (!$resp['ok']) {
            throw new RuntimeException('技能调用失败(' . $resp['code'] . '): ' . $resp['msg']);
        }
        return $resp['data']['result'] ?? null;
    }

    // ------------------------------------------------------------
    // ⑩ 离线激活（内网/断网场景）
    // ------------------------------------------------------------

    /**
     * 生成本机离线激活请求（JSON 字符串，保存为文件交管理员签发）
     * @return string 激活请求 JSON（含 type/version/product_code/license_key/machine_code/machine_name/request_id/requested_at）
     * @throws RuntimeException 机器指纹采集失败或配置缺失
     */
    public function createOfflineRequest(): string
    {
        $machineCode = $this->currentMachineCode();
        if ($machineCode === '') {
            throw new RuntimeException('无法采集机器指纹：请检查 app_secret 配置');
        }
        if ($this->config['product_code'] === '' || $this->config['license_key'] === '') {
            throw new RuntimeException('缺少 product_code 或 license_key 配置');
        }
        $request = [
            'type'          => 'dcai_offline_request',
            'version'       => 1,
            'product_code'  => $this->config['product_code'],
            'license_key'   => $this->config['license_key'],
            'machine_code'  => $machineCode,
            'machine_name'  => $this->machineName(),
            'request_id'    => bin2hex(random_bytes(16)),
            'requested_at'  => date('Y-m-d H:i:s'),
        ];
        return json_encode($request, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 导入并验签「离线激活文件」，成功后本机进入离线授权状态
     * @param string $activationJson 激活文件内容（JSON）
     * @return bool 验签+机器匹配通过并生效 true；失败 false（可 getLastOfflineError() 查看原因）
     */
    public function applyOfflineActivation(string $activationJson): bool
    {
        $data = json_decode($activationJson, true);
        if (!is_array($data)) {
            $this->lastOfflineError = '激活文件格式错误';
            return false;
        }
        if (($data['type'] ?? '') !== 'dcai_offline_activation') {
            $this->lastOfflineError = '不是有效的离线激活文件';
            return false;
        }
        // 1. RSA 验签（验证令牌签名格式：payload."."签名，公钥在 config 中）
        $signature = (string)($data['signature'] ?? '');
        $payload = $data;
        unset($payload['signature']);
        // 签名内容是 payload JSON 的 base64url 编码 + 签名，与签发端 signVerificationToken 对应
        // SDK 验签路径: 对「剔除 signature 后按原字段序 JSON」无法直接复现 base64url → 改为验签 token 结构
        // 签发端实际签名的是 $activatePayload（不含 signature）的 JSON → 这里用同样的 JSON 串验签
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->lastOfflineError = '';
        $ok = $this->verifyOfflineSignature($payloadJson, $signature);
        if (!$ok) {
            $this->lastOfflineError = $this->lastOfflineError ?: '签名验证失败';
            return false;
        }
        // 2. 到期校验
        if (!empty($data['expire_at']) && strtotime((string)$data['expire_at']) < time()) {
            $this->lastOfflineError = '离线激活已到期';
            return false;
        }
        // 3. 机器匹配
        $myCode = $this->currentMachineCode();
        if ($myCode === '' || !hash_equals(strtolower($data['machine_code'] ?? ''), strtolower($myCode))) {
            // 机器码以摘要比对；为空说明本机无法采集同算法指纹
            if (!empty($data['machine_code']) && $myCode !== '' && strcasecmp($data['machine_code'], $myCode) !== 0) {
                $this->lastOfflineError = '激活文件与本机指纹不匹配';
                return false;
            }
        }
        // 4. 生效：写入离线授权缓存（token 结构，verify() 优先读取）
        $this->cache->set('offline_activation', [
            'file'       => $data,
            'expire_at'  => $data['expire_at'] ?? null,
            'activated_at' => date('Y-m-d H:i:s'),
        ], 0);
        $this->cache->delete('revoked');
        return true;
    }

    /**
     * 验签：签名 = base64url(payload JSON) . "." . base64url(RSA-SHA256(sig))
     * 与 DCAI_Signature::signVerificationToken 完全一致
     */
    private function verifyOfflineSignature(string $payloadJson, string $signature): bool
    {
        $pub = $this->config['rsa_public_key'];
        if ($pub === '') {
            $this->lastOfflineError = '未配置 RSA 公钥，无法验签';
            return false;
        }
        $parts = explode('.', $signature, 2);
        if (count($parts) !== 2) {
            $this->lastOfflineError = '签名格式错误';
            return false;
        }
        [$encoded, $sigB64] = $parts;
        // 【安全】外层 payload 重新编码必须与签名串 encoded 完全一致（防篡改外层字段）。
        // 签发端签名的是 base64urlEncode(json_encode(payload, JSON_UNESCAPED_UNICODE))；
        // 若字段顺序不同导致编码不一致，说明文件被改动，直接拒绝。
        $reEncoded = DCAI_Signature::base64UrlEncode($payloadJson);
        if (!hash_equals($encoded, $reEncoded)) {
            $this->lastOfflineError = '激活文件内容与签名不符（已被篡改）';
            return false;
        }
        $pkey = @openssl_pkey_get_public($pub);
        if (!$pkey) {
            $this->lastOfflineError = 'RSA 公钥无效';
            return false;
        }
        $ok = @openssl_verify($encoded, base64_decode(strtr($sigB64, '-_', '+/')), $pkey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            $this->lastOfflineError = '签名验证失败';
            return false;
        }
        return true;
    }

    /**
     * 查看是否处于离线激活状态（即使断网也可通过 guard）
     */
    public function hasOfflineActivation(): bool
    {
        return $this->cache->has('offline_activation');
    }

    /**
     * 获取离线激活信息（含到期时间）
     */
    public function getOfflineActivation(): ?array
    {
        $d = $this->cache->get('offline_activation');
        return is_array($d) ? $d : null;
    }

    private string $lastOfflineError = '';

    /**
     * 最后一次离线激活错误信息
     */
    public function lastOfflineError(): string
    {
        return $this->lastOfflineError;
    }

    // ------------------------------------------------------------
    // 周期任务入口
    // ------------------------------------------------------------

    public function runHooks(): void
    {
        if (!$this->config['enabled']) {
            return;
        }
        if (!$this->verify()) {
            return; // 未授权不执行周期任务
        }
        $lastHeartbeat = (int)$this->cache->get('last_heartbeat', 0);
        if (time() - $lastHeartbeat >= (int)$this->config['heartbeat_interval']) {
            $resp = $this->heartbeat();
            if (!$resp['ok']) {
                // 心跳失败（网络异常/鉴权失败）：不继续命令轮询，避免叠加网络压力
                $code = (int)($resp['code'] ?? -1);
                if ($code === 1003 || $code === 2100) {
                    // 令牌过期/失效：清除实例凭证，下次请求自动重新注册（token 自动重签）
                    $this->logWarning('心跳鉴权失败(' . $code . ')，清除实例凭证，将自动重新注册');
                    $this->cache->delete('instance');
                } elseif ($code === 2008) {
                    $this->logWarning('实例已被远程禁用');
                    $this->cache->set('revoked', time(), 0);
                }
                return;
            }
            if (!empty($resp['data']['revoked'])) {
                $this->cache->set('revoked', time(), 0);
                return;
            }
            // 心跳响应携带新版本提示：有更新则缓存，无更新则清除旧提示，避免"幽灵更新"残留
            if (!empty($resp['data']['new_version'])) {
                $this->cache->set('new_version_notice', $resp['data']['new_version'], 3600);
            } else {
                $this->cache->delete('new_version_notice');
            }
            $this->pollCommands();
        }
    }

    // ------------------------------------------------------------
    // 守卫
    // ------------------------------------------------------------

    public function isRevoked(): bool
    {
        return $this->cache->has('revoked');
    }

    /**
     * 集成守卫：未授权或已被远程禁用时执行 $fail 回调
     */
    public function guard(callable $fail): void
    {
        if ($this->isRevoked()) {
            call_user_func($fail);
            exit;
        }
        if (!$this->config['enabled']) {
            return; // 开关关闭 = 放行
        }
        if (!$this->verify()) {
            call_user_func($fail);
            exit;
        }
    }

    // ------------------------------------------------------------
    // 环境信息
    // ------------------------------------------------------------

    private function currentDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return strtolower(trim((string)$host));
    }

    private function currentIp(): string
    {
        // 仅在服务器明确部署在反向代理之后并配置 proxy_headers=true 时才信任转发头，
        // 避免被授权站点侧恶意注入 X-Forwarded-For/X-Real-IP 伪造客户端 IP 绕过 IP 白名单
        $useProxyHeaders = !empty($this->config['proxy_headers']);
        if ($useProxyHeaders) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
                if (!empty($_SERVER[$k])) {
                    $ip = trim(explode(',', $_SERVER[$k])[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        // REMOTE_ADDR 是服务器确认的直连 IP（无法被 HTTP 头伪造），Web 请求与 CLI 模拟环境均优先采用
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        // CLI（cron/队列）场景无 HTTP 环境，回退到本机出口 IP / hostname IP，避免 0.0.0.0 触发 IP 白名单拒审
        if (PHP_SAPI === 'cli') {
            $ip = gethostbyname(gethostname());
            return $ip !== gethostname() && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
        }
        return '127.0.0.1';
    }

    private function serverInfo(): array
    {
        $mem = function_exists('memory_get_usage') ? (int)(memory_get_usage(true) / 1048576) : 0;
        return [
            'php_version' => PHP_VERSION,
            'os'          => PHP_OS . ' ' . (php_uname('r') ?: ''),
            'memory_mb'   => $mem,
            'disk_free_mb' => function_exists('disk_free_space') ? (int)(@disk_free_space($this->config['app_root']) / 1048576) : 0,
        ];
    }

    private function dbInfo(): array
    {
        return ['type' => 'mysql', 'version' => 'unknown'];
    }

    // ------------------------------------------------------------
    // 机器指纹（机器码/硬件指纹绑定）
    // ------------------------------------------------------------

    /**
     * 采集当前机器的硬件指纹原文（仅用于本地计算摘要，绝不直传服务器）
     * 与 service/MachineService.php 的 machineCode() 算法完全一致：
     *   部件(k=v 小写) → 排序 → "\n" 拼接 → SHA256 → HMAC-SHA256(app_secret) → 64位hex
     * @return string 64 位 hex 机器码；无法采集时返回 ''（不强制绑定场景）
     */
    public function currentMachineCode(): string
    {
        $secret = (string)$this->config['app_secret'];
        if ($secret === '') {
            return ''; // 未配置密钥时无法生成可比较的机器码
        }
        $raw = $this->machineFingerprint();
        if ($raw === '') {
            return '';
        }
        return hash_hmac('sha256', hash('sha256', $raw), $secret);
    }

    /**
     * 采集指纹原文（平台/主机名/CPU/磁盘/网卡MAC/操作系统）
     * 缓存于内存防止每次请求重复采集（采集本身有轻微开销）
     * @return string 规范化指纹原文（"k=v" 行，已排序），失败返回 ''
     */
    public function machineFingerprint(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $data = [];
        // 平台
        $data['platform'] = php_uname('s') ?: PHP_OS;
        // 主机名
        $hostname = gethostname();
        $data['hostname'] = $hostname ?: '';
        // CPU（Linux /proc/cpuinfo；Windows 先用 PROCESSOR_IDENTIFIER 环境变量，wmic 兜底）
        $cpu = '';
        if (is_file('/proc/cpuinfo')) {
            $lines = @file('/proc/cpuinfo');
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (stripos($line, 'model name') === 0 || stripos($line, 'Hardware') === 0 || stripos($line, 'Serial') === 0) {
                        $cpu = trim(substr($line, strpos($line, ':') + 1));
                        break;
                    }
                }
            }
        }
        if ($cpu === '') {
            $envCpu = getenv('PROCESSOR_IDENTIFIER');
            if ($envCpu === false || $envCpu === '') {
                $envCpu = (string)($_SERVER['PROCESSOR_IDENTIFIER'] ?? '');
            }
            $cpu = trim((string)$envCpu);
        }
        if ($cpu === '' && function_exists('exec') && stripos(PHP_OS, 'WIN') === 0) {
            $out = @exec('wmic cpu get name /value 2>&1');
            if (is_string($out) && strpos($out, 'Name=') !== false) {
                $cpu = trim(str_replace(['Name=', 'Name'], '', $out));
            }
        }
        $data['cpu'] = $cpu;
        // 磁盘序列号（Linux root 分区 / Windows C 盘）
        $disk = '';
        if (is_file('/proc/self/mountinfo')) {
            $lines = @file('/proc/self/mountinfo');
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (stripos($line, ' / / ') !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        if (is_array($parts) && count($parts) > 5) {
                            $disk = $parts[5] ?? '';
                        }
                        break;
                    }
                }
            }
        }
        if ($disk === '' && stripos(PHP_OS, 'WIN') === 0) {
            $disk = getenv('SystemDrive') ?: 'C:';
        }
        $data['disk'] = $disk;
        // 网卡 MAC（Linux 读 /sys/class/net 排除回环；Windows getmac 兜底）
        $mac = '';
        if (is_dir('/sys/class/net')) {
            $nets = @scandir('/sys/class/net');
            if (is_array($nets)) {
                foreach ($nets as $iface) {
                    if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                        continue;
                    }
                    $addr = @file_get_contents('/sys/class/net/' . $iface . '/address');
                    if (is_string($addr) && preg_match('/^[0-9a-f:]{17}$/i', trim($addr)) && trim($addr) !== '00:00:00:00:00:00') {
                        $mac = strtolower(trim($addr));
                        break;
                    }
                }
            }
        }
        if ($mac === '' && function_exists('exec') && stripos(PHP_OS, 'WIN') === 0) {
            $out = @exec('getmac 2>&1');
            if (is_string($out)) {
                foreach (explode("\n", $out) as $ln) {
                    if (preg_match('/([0-9A-F]{2}[-:][0-9A-F]{2}[-:][0-9A-F]{2}[-:][0-9A-F]{2}[-:][0-9A-F]{2}[-:][0-9A-F]{2})/i', $ln, $m)) {
                        $mac = strtolower(str_replace('-', ':', $m[1]));
                        break;
                    }
                }
            }
        }
        $data['mac'] = $mac;
        // 操作系统版本
        $data['os'] = php_uname('r') ?: '';
        // 与 MachineService 一致：按 k=v 小写、排序、换行拼接
        $parts = [];
        foreach (['platform', 'hostname', 'cpu', 'disk', 'mac', 'os'] as $k) {
            $v = trim((string)($data[$k] ?? ''));
            if ($v !== '') {
                $parts[] = $k . '=' . strtolower($v);
            }
        }
        sort($parts);
        $cached = implode("\n", $parts);
        return $cached;
    }

    /**
     * 机器显示名（后台"机器绑定"页展示用）
     * @return string 形如 "主机名 (OS Kernel)"，可读标识
     */
    public function machineName(): string
    {
        $host = $this->currentDomain();
        $os = php_uname('r') ?: '';
        $name = trim($host . ' / PHP ' . PHP_VERSION . ($os !== '' ? ' / ' . $os : ''));
        return mb_substr($name, 0, 255);
    }

    private function remainingSeconds(string $expireAt): int
    {
        $ts = strtotime($expireAt);
        if ($ts === false || $ts <= time()) {
            return 3600;
        }
        return $ts - time();
    }

    /**
     * 读取缓存的"新版本提示"（由 runHooks 心跳写入）
     * @return array|null ['version','changelog','is_force',...] 或 null
     */
    public function getUpdateNotice(): ?array
    {
        $notice = $this->cache->get('new_version_notice');
        return is_array($notice) ? $notice : null;
    }

    /**
     * 一键诊断：返回本地状态概览，便于集成方/客服远程排查
     * 不抛出异常，任何失败都以字段形式返回
     */
    public function getDiagnostics(): array
    {
        $out = [
            'time'          => date('Y-m-d H:i:s'),
            'product_code'  => (string)$this->config['product_code'],
            'enabled'       => (bool)$this->config['enabled'],
            'server_url'    => (string)$this->config['server_url'],
            'cache_dir'     => (string)$this->config['cache_dir'],
            'cache_writable'=> is_dir($this->config['cache_dir']) && is_writable($this->config['cache_dir']),
            'php_version'   => PHP_VERSION,
            'sapi'          => PHP_SAPI,
        ];
        // 缓存状态
        $token = $this->cache->get('verification_token');
        $out['token'] = is_array($token) && !empty($token['token'])
            ? ['valid' => true, 'expire_at' => $token['expire_at'] ?? '']
            : ['valid' => false];
        $instance = $this->cache->get('instance');
        $out['instance_registered'] = is_array($instance) && !empty($instance['instance_id']);
        $out['revoked'] = $this->isRevoked();
        $out['policy'] = $this->serverPolicy();
        // 当前上报值
        $out['reported'] = [
            'domain' => $this->currentDomain(),
            'ip'     => $this->currentIp(),
        ];
        // 连通性探测（5 秒超时，不阻塞主流程太久）
        // server_url 形如 http://host/api/v1/，healthz 位于同源根目录 /api/healthz
        $base = rtrim((string)$this->config['server_url'], '/');
        if (substr($base, -8) === '/api/v1') {
            $url = substr($base, 0, -8) . '/healthz';
        } else {
            $url = $base . '/api/healthz';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        if (!empty($this->config['ssl_verify'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            $out['server_reachable'] = false;
            $out['server_error'] = 'curl errno ' . $errno;
        } elseif ($http === 200) {
            $out['server_reachable'] = true;
            $health = json_decode($raw, true);
            $out['server_status'] = is_array($health) ? ($health['status'] ?? 'unknown') : 'unknown';
            $out['server_version'] = is_array($health) ? ($health['version'] ?? '') : '';
        } else {
            $out['server_reachable'] = false;
            $out['server_error'] = 'HTTP ' . $http;
        }
        return $out;
    }

    private function logWarning(string $msg): void
    {
        $file = rtrim($this->config['cache_dir'], '/\\') . '/dcai_alerts.log';
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] [WARNING] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
