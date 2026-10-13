<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\HttpError;
use App\Core\Http\Request;

/**
 * حماية CSRF برمز مرتبط بالجلسة ومقارن بزمن ثابت.
 */
final class Csrf
{
    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function verify(Request $request): void
    {
        if (!Auth::check()) {
            throw new HttpError('unauthenticated', 'الجلسة منتهية. أعد تسجيل الدخول.', 401);
        }
        $sent = (string) ($request->header('X-CSRF-Token') ?? $request->input('_csrf') ?? '');
        $real = self::token();
        if ($sent === '' || !hash_equals($real, $sent)) {
            throw new HttpError('invalid_csrf', 'رمز الحماية غير صالح. حدّث الصفحة وأعد المحاولة.', 419);
        }
    }
}