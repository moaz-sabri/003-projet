<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * فحص تسريب المسارات الحسّاسة — يكشف ما إذا كان الخادم يحترم .htaccess.
 *
 * لماذا هذا الفحص؟
 * ---------------
 * حماية المجلدات الحسّاسة (قاعدة البيانات، بيانات الدخول، الشيفرة) تعتمد
 * كليًا على قواعد .htaccess. بعض استضافات Apache مُهيّأة بـ AllowOverride None
 * (وهي القيمة الافتراضية في Apache)، فتُتجاهل ملفات .htaccess بصمت.
 * النتيجة في تلك الحالة: قاعدة البيانات كاملة قابلة للتحميل بلا كلمة مرور،
 * دون أي رسالة خطأ في أي مكان — لأن كل شيء «يعمل» ظاهريًا.
 *
 * هذا الفحص يطلب المسارات الحسّاسة من الخارج ويحدّد إن كانت مكشوفة،
 * ليعرض على المستخدم تحذيرًا واضحًا بدل أن يكتشف المشكلة بعد أشهر.
 *
 * @package App\Core\Security
 */
final class ExposureCheck
{
    /**
     * المسارات التي يجب ألّا تُخدَم أبدًا (نسبةً إلى مجلد الموقع).
     */
    public const SENSITIVE_PATHS = [
        'storage/database/app.sqlite' => 'قاعدة البيانات (كل الطلبات وأسماء الزبائن)',
        'storage/config/local.php'    => 'ملف بيانات الدخول (تجزئة كلمة مرور المدير)',
        'src/bootstrap.php'           => 'شيفرة الخادم',
        'src/Config/ProjectConfig.php' => 'إعدادات البنية',
        'bin/console'                 => 'أداة الطرفية',
        'tests/run.php'               => 'ملفات الاختبارات',
    ];

    /** ردود تعني «غير محمي» — قد تُنفَّذ ملفات PHP وتعود فارغة لكن الحماية معطّلة. */
    private const EXPOSED_STATUSES = [200];

    /**
     * ينفّذ الفحص ويعيد نتيجة قابلة للعرض.
     *
     * @return array{state:string,checked:int,exposed:list<array>,hint:string}
     */
    public static function run(string $baseUrl, int $timeout = 6, ?string $displayUrl = null): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        // الرابط المعروض للمستخدم (قد يختلف عن الرابط الذي استجاب فعلًا،
        // مثل فحص داخلي على 127.0.0.1 بينما رابطه الحقيقي على النطاق)
        $displayUrl = $displayUrl !== null && trim($displayUrl) !== ''
            ? rtrim(trim($displayUrl), '/')
            : $baseUrl;

        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return self::result('unknown', 0, [], 'تعذّر الفحص: رابط الموقع غير صالح.');
        }

        if (!function_exists('file_get_contents') || ini_get('allow_url_fopen') === '0') {
            return self::result(
                'unknown',
                0,
                [],
                'تعذّر الفحص: فتح روابط HTTP معطّل في إعدادات PHP على هذا الخادم.'
            );
        }

        $exposed   = [];
        $checked   = 0;
        $responded = 0;

        foreach (self::SENSITIVE_PATHS as $path => $label) {
            $status = self::statusOf($baseUrl . '/' . $path, $timeout);

            if ($status === null) {
                continue; // لا استجابة — نتجاهل هذا المسار في هذه المحاولة
            }

            $checked++;
            $responded++;

            if (in_array($status, self::EXPOSED_STATUSES, true)) {
                $exposed[] = ['path' => $path, 'label' => $label, 'status' => $status];
            }
        }

        // لا استجابة واحدة: غالبًا الخادم يمنع الطلبات إلى نفسه — لا نُطلق إنذارًا كاذبًا
        if ($responded === 0) {
            return self::result(
                'unknown',
                0,
                [],
                'تعذّر الفحص: الخادم لم يستجب لطلبات ذاتية (قد يمنعها المضيف). '
                    . 'تأكّد يدويًا أن رابط قاعدة البيانات لا يُفتح في المتصفح.'
            );
        }

        if ($exposed === []) {
            return self::result('secure', $checked, [], 'المسارات الحسّاسة محميّة.');
        }

        return self::result('exposed', $checked, $exposed, self::remediation($displayUrl));
    }

    /** حالة الاستجابة، أو null إذا تعذّر الوصول. */
    private static function statusOf(string $url, int $timeout): ?int
    {
        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'header'          => "Accept: */*\r\n",
        ]]);

        $body = @file_get_contents($url, false, $context, 0, 1);

        if ($body === false && empty($http_response_header)) {
            return null;
        }

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** رسالة الحل الجذري. */
    private static function remediation(string $baseUrl): string
    {
        $canary = $baseUrl . '/storage/database/app.sqlite';

        return "خادمك لا يقرأ ملفات .htaccess، أي أن حماية المجلدات الحسّاسة معطّلة."
            . "\n\n"
            . "السبب المحتمل: إعداد Apache يستخدم \"AllowOverride None\"، وهو الافتراضي."
            . "\n\n"
            . "الحل الأول — تفعيل حماية الخادم:"
            . "\n"
            . "  • cPanel: Domains ← اختر النطاق ← من قسم \"Apache Handlers\""
            . "\n"
            . "    فعّل \"Index Files\" أو اجعل AllowOverride = All."
            . "\n"
            . "  • بقية اللوحات: فعّل mod_rewrite و AllowOverride All للنطاق."
            . "\n\n"
            . "الحل الثاني — لا يعتمد على إعدادات الخادم (الأضمن):"
            . "\n"
            . "  انقل مجلد storage خارج مجلد الموقع public_html، مثلًا إلى"
            . "\n"
            . "  /home/USER/private-data/ ثم عدّل قيمة 'db.path' في ملف"
            . "\n"
            . "  storage/config/local.php ليشير إلى المسار الجديد. هكذا لا يستطيع"
            . "\n"
            . "  المتصفح الوصول إلى قاعدة البيانات مهما كانت إعدادات Apache."
            . "\n\n"
            . "للتحقق بعد الإصلاح، افتح هذا العنوان في المتصفح:"
            . "\n"
            . '  ' . $canary
            . "\n"
            . "يجب أن ترى \"ممنوع\" أو \"غير موجود\" — ولا يجوز أن يُنزَّل الملف.";
    }

    /**
     * @param list<array{path:string,label:string,status:int}> $exposed
     * @return array{state:string,checked:int,exposed:list<array>,hint:string}
     */
    private static function result(string $state, int $checked, array $exposed, string $hint): array
    {
        return [
            'state'   => $state,
            'checked' => $checked,
            'exposed' => $exposed,
            'hint'    => $hint,
        ];
    }
}