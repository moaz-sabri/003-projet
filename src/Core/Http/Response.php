<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * استجابات JSON موحّدة + استجابة أخطاء عربية.
 */
final class Response
{
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function ok(array $data = [], int $status = 200, array $headers = []): void
    {
        self::json(['ok' => true, 'data' => $data], $status, $headers);
    }

    public static function error(string $code, string $message, int $status = 400, array $extra = []): void
    {
        $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra];
        self::json($payload, $status);
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    public static function redirect(string $location, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $location);
        exit;
    }
}