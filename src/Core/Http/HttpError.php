<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * خطأ HTTP مصنّف — يتحول إلى استجابة JSON منظّمة في api/index.php.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $status = 400,
        private array $extra = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function extra(): array
    {
        return $this->extra;
    }
}