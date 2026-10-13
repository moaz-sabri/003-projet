<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * تحقق بسيط ومباشر من المدخلات (يعمل على المصفوفة بعد فك JSON).
 * يرمي HttpError عند الفشل، وتُجمع الرسائل بلغة عربية.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '' || (is_array($v) && $v === [])) {
            $this->errors[$field] = $label . ' مطلوب.';
        }
        return $this;
    }

    public function string(string $field, string $label, int $min = 0, int $max = 500): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        $v = (string) $v;
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$field] = $label . ' قصير جدًا.';
        }
        if ($len > $max) {
            $this->errors[$field] = $label . ' طويل جدًا.';
        }
        $this->data[$field] = $v;
        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_numeric($v)) {
            $this->errors[$field] = $label . ' يجب أن يكون رقمًا.';
            return $this;
        }
        if (!self::isWholeNumber((string) $v)) {
            $this->errors[$field] = $label . ' يجب أن يكون رقمًا صحيحًا.';
            return $this;
        }
        $int = (int) $v;
        if ($min !== null && $int < $min) {
            $this->errors[$field] = $label . ' يجب ألا يقل عن ' . $min . '.';
        }
        if ($max !== null && $int > $max) {
            $this->errors[$field] = $label . ' يجب ألا يزيد عن ' . $max . '.';
        }
        $this->data[$field] = $int;
        return $this;
    }

    /** هل النص يمثّل عددًا صحيحًا (يقبل + و - و ٠٠٠)؟ */
    private static function isWholeNumber(string $v): bool
    {
        return (bool) preg_match('/^-?\d{1,15}$/', trim($v));
    }

    public function boolean(string $field): self
    {
        $v = $this->value($field);
        if ($v === null) {
            return $this;
        }
        $this->data[$field] = self::toBool($v) ? 1 : 0;
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!in_array((string) $v, array_map('strval', $allowed), true)) {
            $this->errors[$field] = $label . ' غير صالح.';
        }
        return $this;
    }

    public function pattern(string $field, string $pattern, string $message): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!preg_match($pattern, (string) $v)) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): self
    {
        $this->data[$field] = $value;
        return $this;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->data;
    }

    public function throwIfFails(): array
    {
        if ($this->fails()) {
            throw new HttpError('validation', 'تحقّق من الحقول المميزة.', 422, ['fields' => $this->errors]);
        }
        return $this->data;
    }

    public static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes', 'نعم'], true);
    }
}