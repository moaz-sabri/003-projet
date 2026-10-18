<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * فحص تسريب المسارات الحسّاسة — يكشف ما إذا كان الخادم يطبّق قواعد الحماية.
 *
 * لماذا هذا الفحص؟
 * ---------------
 * حماية المجلدات الحسّاسة (قاعدة البيانات، بيانات الدخول، الشيفرة) تعتمد
 * كليًا على إعداد nginx. كثير من الاستضافات المشتركة — وبعض لوحات
 * التحكم — تقدّم موقعًا جاهزًا بلا صلاحية تعديل الإعدادات، فتصل
 * الملفات إلى `/var/www/html` بلا قاعدة تحجبها، والقاعدة قابلة للتحميل
 * بلا كلمة مرور: **دون أي رسالة خطأ في أي مكان**، لأن كل شيء «يعمل»
 * ظاهريًا.
 *
 * nginx لا يقرأ .htaccess إطلاقًا، ولا يبحث عن قواعد في مجلد فرعي.
 * لذلك إمّا يُركَّب الملف المُسلَّم في deploy/nginx/site.conf، أو لا
 * حماية البنية إطلاقًا. هذا الفحص يكشف الحالة الثانية.
 *
 * @package App\Core\Security
 */
final class ExposureCheck
{
    /**
     * المسارات التي يجب ألّا تُخدَم أبدًا (نسبةً إلى مجلد الموقع).
     *
     * severity = critical : بيانات حسّاسة (طلبات، أسماء، كلمات مرور)
     * severity = source   : شيفرة المالك — أقل خطورة، فقد يقرأها صاحب الموقع
     *                        original، لكن يُفضّل عدم نشرها.
     */
    public const SENSITIVE_PATHS = [
        'storage/database/app.sqlite' => 'قاعدة البيانات (كل الطلبات وأسماء الزبائن)',
        'storage/config/local.php'    => 'ملف بيانات الدخول (تجزئة كلمة مرور المدير)',
        'storage/backups/backup'      => 'النسخ الاحتياطية (نسخة كاملة من البيانات)',
        // نسخة أمان التدمير: تحوي القاعدة + بيانات الدخول معًا، فتسريبها
        // أخطر من تسريب القاعدة نفسها — لأنها تبقى بعد محو النسخة.
        'storage/destroyed/app.sqlite' => 'نسخ أمان التدمير (قاعدة + بيانات دخول)',
        'src/bootstrap.php'           => 'شيفرة الخادم',
        'src/Config/ProjectConfig.php' => 'إعدادات البنية',
        'bin/console'                 => 'أداة الطرفية',
        'tests/run.php'               => 'ملفات الاختبارات',
        // أرشيف في جذر الموقع يجعل كل protections أعلاه بلا معنى: يُنزَّل
        // الملف الواحد وفيه مجلد storage كاملًا. هذا أخطر تسرب ممكن.
        'setup.zip'                   => 'أرشيف المشروع (فيه قاعدة البيانات وكلمة المرور)',
    ];

    /** المسارات التي تسريبها يُعرّض بيانات الزبائن أو المدير. */
    private const CRITICAL_PATHS = [
        'storage/database/app.sqlite',
        'storage/config/local.php',
        'storage/backups/backup',
        'storage/destroyed/app.sqlite',
        'setup.zip',
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
                $exposed[] = [
                    'path'     => $path,
                    'label'    => $label,
                    'status'   => $status,
                    'severity' => in_array($path, self::CRITICAL_PATHS, true) ? 'critical' : 'source',
                ];
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
            return self::result('secure', $checked, [], 'المسارات الحسّاسة محميّة.', 0);
        }

        $critical = 0;
        foreach ($exposed as $item) {
            if ($item['severity'] === 'critical') {
                $critical++;
            }
        }

        // البيانات الحسّاسة مكشوفة = أخطر من كشف شيفرة المالك
        return self::result(
            $critical > 0 ? 'critical' : 'source',
            $checked,
            $exposed,
            self::remediation($displayUrl, $critical > 0),
            $critical
        );
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

    /**
     * رسالة الحل — تختلف بحسب خطورة التسريب.
     *
     * كشف شيفرة المالك أقل خطورة من كشف قاعدة بيانات الزبائن،
     * فلا نُطلق إنذارًا واحدًا بحدّة واحدة حتى لا يفقد الأداة مصداقيتها.
     */
    private static function remediation(string $baseUrl, bool $critical): string
    {
        $canary = $baseUrl . '/storage/database/app.sqlite';

        if (!$critical) {
            return "لا توجد بيانات زبائن أو بيانات دخول مكشوفة ✔"
                . "\n\n"
                . "ما زال بإمكان الزوار قراءة شيفرة المشروع (bin/console وغيرها)."
                . "\n"
                . "هذا أقل خطورة لأن صاحب النسخة يملك هذه الشيفرة أصلًا."
                . "\n\n"
                . "لإخفائها بالكامل على استضافة لا تتيح تعديل إعدادات الخادم،"
                . "\n"
                . "احذف المجلدات غير اللازمة من النسخة المنشورة:"
                . "\n"
                . "  bin/  tests/  tools/  docs/"
                . "\n\n"
                . "وللتأكّد من حماية البيانات: افتح " . $canary
                . "\n"
                . "يجب أن يظهر «ممنوع» أو «غير موجود».";
        }

        return "⛔ بيانات حسّاسة مكشوفة على الإنترنت"
            . "\n\n"
            . "خادمك لا يمنع الوصول إلى مجلدات البيانات. أي شخص يفتح الرابط"
            . "\n"
            . "يستطيع تنزيل قاعدة البيانات كاملة أو قراءة تجزئة كلمة مرور المدير."
            . "\n\n"
            . "السبب: إعداد nginx غير مُركَّب، أو أن مجلد الموقع خارج الجذر"
            . "\n"
            . "الذي يشير إليه root، أو نُسخت الملفات إلى مكان لا تطبَّق فيه القواعد."
            . "\n\n"
            . "الحل الموصى به — ثبّت الملف المُسلَّم مع الحزمة:"
            . "\n\n"
            . "  cp deploy/nginx/site.conf /etc/nginx/sites-available/light-commerce"
            . "\n"
            . "  ln -s /etc/nginx/sites-available/light-commerce \\"
            . "\n"
            . "        /etc/nginx/sites-enabled/light-commerce"
            . "\n"
            . "  nginx -t && systemctl reload nginx"
            . "\n\n"
            . "وإن لم تكن able لتعديل إعدادات الخادم (استضافة مشتركة):"
            . "\n"
            . "  php tools/move-outside-webroot.php --yes"
            . "\n"
            . "  ينقل قاعدة البيانات وملف الإعدادات خارج مجلد الموقع"
            . "\n"
            . "  ويحدّث المسارات والصلاحيات تلقائيًا."
            . "\n\n"
            . "للتحقق بعد الإصلاح، افتح: " . $canary
            . "\n"
            . "يجب أن ترى \"ممنوع\" أو \"غير موجود\" — ولا يجوز أن يُنزَّل الملف.";
    }

    /**
     * @param list<array{path:string,label:string,status:int}> $exposed
     * @return array{state:string,checked:int,exposed:list<array>,hint:string}
     */
    private static function result(string $state, int $checked, array $exposed, string $hint, int $critical = 0): array
    {
        return [
            'state'    => $state,
            'critical' => $critical,
            'checked' => $checked,
            'exposed' => $exposed,
            'hint'    => $hint,
        ];
    }
}
