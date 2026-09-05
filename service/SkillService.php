<?php
/**
 * 技能（Skill）服务：列表、授权判定、函数调用、调用日志
 *
 * 技能包体系：技能(技能包) = 一组可被被授权站点(壳)远程调用的函数；
 * 核心逻辑托管在授权系统，被授权站点仅按授权范围调用，拿不到核心实现。
 * 授权模型：access_model=0 产品下全部授权实例可用；access_model=1 需按授权码授予(skill_grants)。
 */
class DCAI_SkillService
{
    public const FUNC_TYPE_CODE = 1;
    public const FUNC_TYPE_HTTP = 2;
    public const FUNC_TYPE_SQL  = 3;

    /**
     * 查询某实例可见且可用的技能列表（含其启用的函数），供 SDK getSkills() / skill/list
     */
    public static function listForInstance(int $productId, ?int $licenseId): array
    {
        $db = dcai_db();
        $skills = $db->query(
            "SELECT s.* FROM skills s
             WHERE s.product_id = ? AND s.status = 1
             ORDER BY s.sort_order ASC, s.id DESC",
            [$productId]
        );
        $out = [];
        foreach ($skills as $skill) {
            // access_model=1 需按授权码授予后才能看到/使用技能
            if ((int)$skill['access_model'] === 1 && !self::hasGrant((int)$skill['id'], $licenseId)) {
                continue;
            }
            $fns = $db->query(
                "SELECT function_code, name, description, func_type, params_schema FROM skill_functions
                 WHERE skill_id = ? AND status = 1 ORDER BY id ASC",
                [(int)$skill['id']]
            );
            $functions = [];
            foreach ($fns as $fn) {
                $functions[] = [
                    'function_code' => $fn['function_code'],
                    'name'          => $fn['name'],
                    'description'   => $fn['description'] ?? '',
                    'func_type'     => (int)$fn['func_type'],
                    'params_schema' => $fn['params_schema'] ?? '',
                ];
            }
            $out[] = [
                'skill_code'      => $skill['skill_code'],
                'name'            => $skill['name'],
                'description'     => $skill['description'] ?? '',
                'icon'            => $skill['icon'] ?? '🧩',
                'access_model'    => (int)$skill['access_model'],
                'functions'       => $functions,
            ];
        }
        return $out;
    }

    /**
     * 调用技能函数
     * @return array{0:?int,1:?array} [错误码, 结果]
     */
    public static function invoke(array $instance, string $skillCode, string $functionCode, array $params): array
    {
        $productId = (int)$instance['product_id'];
        $licenseId = !empty($instance['license_id']) ? (int)$instance['license_id'] : null;
        $instanceRowId = (int)$instance['id'];

        $skill = dcai_db()->queryOne(
            'SELECT * FROM skills WHERE skill_code = ? AND product_id = ? AND status = 1',
            [$skillCode, $productId]
        );
        if (!$skill) {
            return [3101, null];
        }
        // 授权判定
        if ((int)$skill['access_model'] === 1 && !self::hasGrant((int)$skill['id'], $licenseId)) {
            return [3102, null];
        }

        $fn = dcai_db()->queryOne(
            'SELECT * FROM skill_functions WHERE skill_id = ? AND function_code = ? AND status = 1',
            [(int)$skill['id'], $functionCode]
        );
        if (!$fn) {
            return [3103, null];
        }

        [$ok, $err] = DCAI_ModuleService::validateParams($fn['params_schema'] ?? '', $params);
        if (!$ok) {
            self::logInvoke($skill, $fn, $instance, $params, null, 0, $err, 0);
            return [3104, null];
        }

        $start = microtime(true);
        try {
            switch ((int)$fn['func_type']) {
                case self::FUNC_TYPE_CODE:
                    [$runOk, $result, $runErr] = DCAI_Sandbox::run($fn['code'] ?? '', $params, [
                        'db'     => dcai_db(),
                        'logger' => DCAI_Logger::instance(),
                    ]);
                    if (!$runOk) {
                        $cost = (int)((microtime(true) - $start) * 1000);
                        self::logInvoke($skill, $fn, $instance, $params, null, 0, $runErr, $cost);
                        return [3105, null];
                    }
                    break;
                case self::FUNC_TYPE_HTTP:
                    $result = DCAI_ModuleService::httpForward($fn['upstream_url'] ?? '', $params);
                    break;
                case self::FUNC_TYPE_SQL:
                    $result = DCAI_ModuleService::dataQuery($fn['sql_template'] ?? '', $params);
                    break;
                default:
                    return [3103, null];
            }
        } catch (Throwable $e) {
            $cost = (int)((microtime(true) - $start) * 1000);
            self::logInvoke($skill, $fn, $instance, $params, null, 0, '技能执行异常: ' . $e->getMessage(), $cost);
            dcai_log('error', '技能执行异常', ['skill' => $skillCode, 'fn' => $functionCode, 'err' => $e->getMessage()]);
            return [3105, null];
        }

        $cost = (int)((microtime(true) - $start) * 1000);
        self::logInvoke($skill, $fn, $instance, $params, $result, 1, '', $cost);
        return [null, ['result' => $result]];
    }

    public static function hasGrant(int $skillId, ?int $licenseId): bool
    {
        if ($licenseId === null || $licenseId <= 0) {
            return false;
        }
        return (int)dcai_db()->queryValue(
            'SELECT COUNT(*) FROM skill_grants WHERE skill_id = ? AND license_id = ? AND status = 1',
            [$skillId, $licenseId]
        ) > 0;
    }

    /**
     * 授予/撤销授权码对某技能的访问权
     */
    public static function setGrant(int $skillId, int $licenseId, int $status, ?int $adminId = null): bool
    {
        $db = dcai_db();
        $existing = $db->queryOne('SELECT id FROM skill_grants WHERE skill_id = ? AND license_id = ?', [$skillId, $licenseId]);
        if ($existing) {
            return $db->update('skill_grants', ['status' => $status, 'created_by' => $adminId], 'id = ?', [(int)$existing['id']]) >= 0;
        }
        return $db->insert('skill_grants', [
            'skill_id'   => $skillId,
            'license_id' => $licenseId,
            'status'     => $status,
            'created_by' => $adminId,
            'created_at' => dcai_now(),
        ]) > 0;
    }

    public static function logInvoke(array $skill, array $fn, array $instance, array $params, $result, int $status, string $error, int $costMs): void
    {
        try {
            dcai_db()->insert('skill_invoke_logs', [
                'skill_id'    => (int)$skill['id'],
                'function_id' => (int)$fn['id'],
                'license_id'  => !empty($instance['license_id']) ? (int)$instance['license_id'] : null,
                'instance_id' => (int)$instance['id'],
                'params'      => json_encode($params, JSON_UNESCAPED_UNICODE),
                'result'      => $result === null ? '' : (is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE)),
                'status'      => $status,
                'error'       => substr($error, 0, 500),
                'cost_ms'     => $costMs,
                'created_at'  => dcai_now(),
            ]);
        } catch (Throwable $e) {
            dcai_log('error', '写入技能调用日志失败', ['err' => $e->getMessage()]);
        }
    }
}