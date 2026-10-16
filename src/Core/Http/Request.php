<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * غلاف بسيط ومقروء حول متغيّرات الطلب في PHP.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $server;

    public function __construct(array $query, array $body, array $files, array $server)
    {
        $this->query  = $query;
        $this->body   = $body;
        $this->files  = $files;
        $this->server = $server;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_FILES, $_SERVER);
    }

    public function method(): string
    {
        $m = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        // دعم method override عبر حقل _method أو ترويسة
        if ($m === 'POST') {
            $override = $this->post('_method') ?? $this->header('X-HTTP-Method-Override');
            // بلا فحص النوع: «_method[]=PUT» كان يرمي TypeError داخل strtoupper
            // فيعطي 500 على مسار عام بلا مصادقة (مصنع أخطاء مفتوح).
            if (is_scalar($override) && $override !== '') {
                $m = strtoupper((string) $override);
            }
        }
        return $m;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** يقرأ من body ثم query (يسهّل الاختبارات). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function body(): array
    {
        return $this->body;
    }

    public function json(): array
    {
        $raw = $this->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function rawBody(): string
    {
        // static داخل دالة = متغيّر واحد لكل العملية لا لكل كائن. تحت
        // PHP-FPM كل طلب عملية جديدة فلا أثر، لكن أي سياق دائم (RoadRunner)
        // أو سكربت ينشئ كائنَي Request يحصل على جسم الطلب الأول دائمًا.
        static $cached = null;
        if ($cached === null) {
            $cached = file_get_contents('php://input') ?: '';
        }
        return $cached;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? null;
        if ($v === null) {
            // بعض الترويسات (Content-Type…) لا تُخزَّن ببادئة HTTP_ في PHP،
            // فالمفتاح الصحيح هو الاسم بحروف كبيرة (مع استبدال الشرطة).
            $alt = strtoupper(str_replace('-', '_', $name));
            $v = $this->server[$alt] ?? null;
        }
        if (!is_string($v)) {
            return $default;
        }
        return $v;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && $https !== 'off') || ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function ip(): string
    {
        // لا نثق بترويسات X-Forwarded-For إلا من trusted proxy (يمكن إضافتها لاحقًا).
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $pos = strpos($uri, '?');
        return $pos === false ? $uri : substr($uri, 0, $pos);
    }

    public function isJsonPreferred(): bool
    {
        $accept = $this->header('Accept', '') ?? '';
        $ctype  = $this->header('Content-Type', '') ?? '';
        return str_contains($ctype, 'application/json') || str_contains($accept, 'application/json');
    }
}