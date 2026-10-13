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
        // دعم-method override عبر حقل _method أو ترويسة
        if ($m === 'POST') {
            $override = $this->post('_method') ?? $this->header('X-HTTP-Method-Override');
            if ($override) {
                $m = strtoupper($override);
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
        static $cached = null;
        if ($cached === null) {
            $cached = file_get_contents('php://input') ?: '';
        }
        return $cached;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $this->server[strtoupper($name)] ?? $default;
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