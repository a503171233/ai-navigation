<?php
/**
 * 远程模块服务：列表、调用、参数校验、三类执行器、调用日志
 */
class DCAI_ModuleService
{
    /**
     * 查询某产品实例可用的模块列表
     */
    public static function listForProduct(int $productId): array
    {
        $rows = dcai_db()->query(
            'SELECT module_code, name, version, description, module_type FROM remote_modules
             WHERE product_id = ? AND status = 1 ORDER BY id DESC',
            [$productId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'module_code' => $row['module_code'],
                'name'        => $row['name'],
                'version'     => $row['version'],
                'description' => $row['description'] ?? '',
                'module_type' => (int)$row['module_type'],
            ];
        }
        return $out;
    }

    /**
     * 调用模块
     * @return array{0:?int,1:?array} [错误码, 结果]
     */
    public static function invoke(int $productId, int $instanceRowId, string $moduleCode, array $params): array
    {
        $module = dcai_db()->queryOne(
            'SELECT * FROM remote_modules WHERE module_code = ? AND product_id = ?',
            [$moduleCode, $productId]
        );
        if (!$module || (int)$module['status'] !== 1) {
            return [3001, null];
        }

        // 参数校验
        [$ok, $err] = self::validateParams($module['params_schema'] ?? '', $params);
        if (!$ok) {
            self::logInvoke($module, $instanceRowId, $params, null, 0, $err, 0);
            return [3002, null];
        }

        $start = microtime(true);
        try {
            switch ((int)$module['module_type']) {
                case 1: // PHP 代码型
                    [$runOk, $result, $runErr] = DCAI_Sandbox::run($module['code'] ?? '', $params, [
                        'db'     => dcai_db(),
                        'logger' => DCAI_Logger::instance(),
                    ]);
                    if (!$runOk) {
                        self::logInvoke($module, $instanceRowId, $params, null, 0, $runErr, (int)((microtime(true) - $start) * 1000));
                        return [3003, null];
                    }
                    break;
                case 2: // HTTP 转发型
                    $result = self::httpForward($module['upstream_url'] ?? '', $params);
                    break;
                case 3: // 数据查询型
                    $result = self::dataQuery($module['sql_template'] ?? '', $params);
                    break;
                default:
                    return [3001, null];
            }
        } catch (Throwable $e) {
            $cost = (int)((microtime(true) - $start) * 1000);
            self::logInvoke($module, $instanceRowId, $params, null, 0, '模块执行异常: ' . $e->getMessage(), $cost);
            dcai_log('error', '模块执行异常', ['module' => $moduleCode, 'err' => $e->getMessage()]);
            return [3003, null];
        }

        $cost = (int)((microtime(true) - $start) * 1000);
        self::logInvoke($module, $instanceRowId, $params, $result, 1, '', $cost);
        return [null, ['result' => $result]];
    }

    /**
     * params_schema 参数校验（JSON Schema 子集）
     * 支持: type(integer|number|string|boolean|array|object), required[], properties[].{type,required,min,max,minLength,maxLength,enum,pattern}
     */
    public static function validateParams(string $schemaJson, array $params): array
    {
        if (trim($schemaJson) === '' || $schemaJson === 'null') {
            return [true, ''];
        }
        $schema = json_decode($schemaJson, true);
        if (!is_array($schema)) {
            return [true, ''];
        }
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $params)) {
                return [false, "缺少必填参数: $field"];
            }
        }
        $properties = $schema['properties'] ?? [];
        foreach ($properties as $field => $rule) {
            if (!array_key_exists($field, $params)) {
                continue;
            }
            $value = $params[$field];
            $type = $rule['type'] ?? null;
            if ($type !== null && !self::checkType($value, $type)) {
                return [false, "参数 $field 类型必须为 $type"];
            }
            if ($type === 'integer' || $type === 'number') {
                $v = (float)$value;
                if (isset($rule['min']) && $v < $rule['min']) {
                    return [false, "参数 $field 不能小于 {$rule['min']}"];
                }
                if (isset($rule['max']) && $v > $rule['max']) {
                    return [false, "参数 $field 不能大于 {$rule['max']}"];
                }
            }
            if ($type === 'string') {
                $s = (string)$value;
                if (isset($rule['minLength']) && mb_strlen($s) < $rule['minLength']) {
                    return [false, "参数 $field 长度过短"];
                }
                if (isset($rule['maxLength']) && mb_strlen($s) > $rule['maxLength']) {
                    return [false, "参数 $field 长度过长"];
                }
                if (!empty($rule['pattern']) && !preg_match('/' . str_replace('/', '\\/', $rule['pattern']) . '/', $s)) {
                    return [false, "参数 $field 格式不符合要求"];
                }
            }
            if (!empty($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                return [false, "参数 $field 取值不在允许范围内"];
            }
        }
        return [true, ''];
    }

    private static function checkType($value, string $type): bool
    {
        switch ($type) {
            case 'integer':
                return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value));
            case 'number':
                return is_numeric($value);
            case 'string':
                return is_string($value) || is_numeric($value);
            case 'boolean':
                return is_bool($value) || $value === 'true' || $value === 'false' || $value === '1' || $value === '0';
            case 'array':
                return is_array($value);
            case 'object':
                return is_array($value);
            default:
                return true;
        }
    }

    /**
     * HTTP 转发型执行（超时与协议白名单）
     */
    public static function httpForward(string $url, array $params): array
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('模块转发地址未配置');
        }
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('转发地址协议不受支持');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || $status >= 400) {
            throw new RuntimeException('转发请求失败 (HTTP ' . $status . ', curl ' . $errno . ')');
        }
        $decoded = json_decode((string)$body, true);
        return $decoded !== null ? $decoded : ['raw' => (string)$body];
    }

    /**
     * 数据查询型执行（仅 SELECT）
     * 模板占位符 {key} -> ?，同名占位符可多次出现（每次出现各自绑定一个参数）
     */
    public static function dataQuery(string $sqlTemplate, array $params): array
    {
        if (trim($sqlTemplate) === '') {
            throw new RuntimeException('模块查询模板未配置');
        }
        if (!preg_match('/^\s*SELECT\b/i', $sqlTemplate)) {
            throw new RuntimeException('模块查询模板仅允许 SELECT');
        }
        // 逐次替换：同一 {key} 出现 N 次则绑定 N 个参数（避免 str_replace 全量替换导致参数数量不匹配）
        $bind = [];
        $sql = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use ($params, &$bind): string {
            $name = $m[1];
            if (!array_key_exists($name, $params)) {
                throw new RuntimeException("查询模板缺少参数: $name");
            }
            $bind[] = $params[$name];
            return '?';
        }, $sqlTemplate) ?? $sqlTemplate;
        return dcai_db()->query($sql, $bind);
    }

    private static function logInvoke(array $module, int $instanceRowId, array $params, $result, int $status, string $error, int $costMs): void
    {
        try {
            dcai_db()->insert('module_invoke_logs', [
                'module_id'   => (int)$module['id'],
                'instance_id' => $instanceRowId ?: null,
                'params'      => json_encode($params, JSON_UNESCAPED_UNICODE),
                'result'      => $result === null ? '' : (is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE)),
                'status'      => $status,
                'error'       => substr($error, 0, 500),
                'cost_ms'     => $costMs,
                'created_at'  => dcai_now(),
            ]);
        } catch (Throwable $e) {
            dcai_log('error', '写入模块调用日志失败', ['err' => $e->getMessage()]);
        }
    }
}
