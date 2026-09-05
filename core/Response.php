<?php
/**
 * 统一 JSON 响应
 * 响应格式: {"code":0,"msg":"ok","data":{...}}
 */
class DCAI_Response
{
    public static function json(int $code, string $msg, $data = null, int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = null, string $msg = 'ok'): void
    {
        self::json(0, $msg, $data);
    }

    public static function fail(int $code, string $msg, $data = null): void
    {
        self::json($code, $msg, $data);
    }

    public static function error(string $msg = '服务器内部错误', int $code = 5000): void
    {
        self::json($code, $msg);
    }
}
