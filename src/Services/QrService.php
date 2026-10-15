<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\ProjectConfig;
use App\Core\Qr\QrCode;

/**
 * توليد رموز QR للمواقع والطلبات — محليًا بالكامل (بدون أي خدمة خارجية).
 *
 * نوعان منفصلان من الرموز (readme §6):
 *  - QR الموقع: يفتح القائمة مع تحديد الموقع (?loc=CODE) ولا ينشئ طلبًا.
 *  - QR الطلب: يفتح تفاصيل الطلب فقط (?t=TOKEN) ولا يمنح أي صلاحية تعديل أو إلغاء.
 */
final class QrService
{
    public function __construct(private QrCode $qr = new QrCode('M'))
    {
    }

    /**
     * الرابط الكامل الذي يُشفَّر في رمز الموقع.
     *
     * الرابط لا يحوي اسم ملف داخليًا: الواجهة تُقدَّم من جذر الموقع، فالرابط
     * المُطبوع قصير ونظيف:  https://example.com/?loc=T05
     * وحتى لو طُبعت رموز QR قبل هذا التحديث فإن /app/index.html يوجّه تلقائيًا
     * إلى الجذر مع الحفاظ على ?loc= (إعادة توجيه 301).
     */
    public function locationUrl(string $baseUrl, string $code): string
    {
        $base = rtrim($baseUrl, '/');
        return $base . '/?loc=' . rawurlencode($code);
    }

    /** رابط متابعة الطلب (قراءة فقط): /order/{الرمز} */
    public function orderUrl(string $baseUrl, string $token): string
    {
        $base = rtrim($baseUrl, '/');
        return $base . '/order/' . rawurlencode($token);
    }

    public function locationSvg(string $baseUrl, string $code, int $size = 8): string
    {
        return $this->qr->svg($this->locationUrl($baseUrl, $code), $size);
    }

    public function orderSvg(string $baseUrl, string $token, int $size = 8): string
    {
        return $this->qr->svg($this->orderUrl($baseUrl, $token), $size);
    }

    public function locationPng(string $baseUrl, string $code, int $size = 8): ?string
    {
        return $this->qr->png($this->locationUrl($baseUrl, $code), $size);
    }

    public function orderPng(string $baseUrl, string $token, int $size = 8): ?string
    {
        return $this->qr->png($this->orderUrl($baseUrl, $token), $size);
    }

    /** رابط printable يحوي عدة رموز في صفحة واحدة للطباعة. */
    public function printSheetHtml(string $baseUrl, array $locations, array $settings): string
    {
        $items = '';
        foreach ($locations as $location) {
            $svg   = $this->locationSvg($baseUrl, $location['code'], 8);
            $title = htmlspecialchars($location['name'], ENT_QUOTES, 'UTF-8');
            $code  = htmlspecialchars($location['code'], ENT_QUOTES, 'UTF-8');
            $app   = htmlspecialchars($settings['app_name'] ?? '', ENT_QUOTES, 'UTF-8');
            $sing  = htmlspecialchars(ProjectConfig::locationSingular(), ENT_QUOTES, 'UTF-8');
            $items .= <<<HTML
                <div class="qr-card">
                  <div class="qr-code">{$svg}</div>
                  <div class="qr-info">
                    <div class="qr-title">{$sing}: {$title}</div>
                    <div class="qr-code-text">{$code}</div>
                    <div class="qr-app">{$app}</div>
                    <div class="qr-hint">امسح الرمز لعرض القائمة وإرسال الطلب</div>
                  </div>
                </div>
            HTML;
        }

        $style = <<<CSS
            * { box-sizing: border-box; }
            body { font-family: system-ui, "Segoe UI", Tahoma, sans-serif; margin: 0; padding: 24px; background: #fff; color: #111; }
            h1 { font-size: 20px; margin: 0 0 4px; }
            p.sub { margin: 0 0 24px; color: #666; font-size: 14px; }
            .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
            .qr-card { border: 1px solid #ddd; border-radius: 12px; padding: 14px; display: flex; gap: 14px; align-items: center; break-inside: avoid; }
            .qr-code svg { width: 118px; height: 118px; display: block; }
            .qr-title { font-weight: 700; font-size: 15px; margin-bottom: 2px; }
            .qr-code-text { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; color: #555; }
            .qr-app { font-size: 12px; color: #888; margin-top: 6px; }
            .qr-hint { font-size: 11px; color: #888; margin-top: 8px; }
            .toolbar { margin-bottom: 18px; }
            button { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid #111; background: #111; color: #fff; cursor: pointer; }
            @media print { .toolbar { display: none; } body { padding: 0; } }
        CSS;

        return <<<HTML
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>رموز QR — {$this->escTitle($settings['app_name'] ?? '')}</title>
        <style>{$style}</style>
        </head>
        <body>
        <div class="toolbar"><button onclick="window.print()">طباعة</button></div>
        <h1>رموز QR</h1>
        <p class="sub">امسح الرمز بفتح القائمة على الموقع المحدد.</p>
        <div class="grid">{$items}</div>
        </body>
        </html>
        HTML;
    }

    private function escTitle(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}