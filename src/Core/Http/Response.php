<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * استجابات JSON موحّدة + استجابة أخطاء عربية.
 */
final class Response
{
    /**
     * رموز الحالة المعتمدة من IANA التي تدعمها كل خوادم الويب.
     *
     * ملاحظة مهمة: الرموز غير المعتمدة (مثل 419 الشائع في Laravel، أو 418)
     * يحوّلها Apache مع mod_php إلى 500، فيختفي سبب الرفض الحقيقي.
     * لذلك نتحقق هنا ونحوّل أي رمز غير قياسي إلى 400 مع تحذير في السجل.
     */
    private const STANDARD_STATUSES = [
        200, 201, 202, 204,
        301, 302, 303, 304, 307, 308,
        400, 401, 402, 403, 404, 405, 406, 409, 410, 413, 415, 422, 429,
        500, 501, 502, 503,
    ];

    private static function normalizeStatus(int $status): int
    {
        if (in_array($status, self::STANDARD_STATUSES, true)) {
            return $status;
        }

        \App\Core\Logger::warn('Non-standard HTTP status ' . $status . ' coerced to 400');

        return 400;
    }

    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        http_response_code(self::normalizeStatus($status));
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