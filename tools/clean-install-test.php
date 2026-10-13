<?php

declare(strict_types=1);

/**
 * اختبار تثبيت نظيف من الصفر — على PHP عادي (خادم مدمج مع الراوتر).
 *
 *   php tools/clean-install-test.php
 *
 * ينسخ المشروع من git (بدون أي ملف تشغيل: لا قاعدة بيانات ولا إعدادات ولا صور)
 * إلى مجلد مؤقت، يهيّئه، يشغّل خادمًا، ثم يشغّل فحص النشر الكامل عليه.
 */

if (PHP_SAPI !== 'cli') {
    exit("هذه الأداة تعمل من الطرفية فقط.\n");
}

$root     = dirname(__DIR__);
$workDir  = sys_get_temp_dir() . '/lc-clean-install';
$port     = (int) ($argv[1] ?? 8097);
$password = 'CleanPass123';

function say(string $message): void
{
    echo $message . "\n";
}

function run(string $command): array
{
    $output   = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return ['code' => $exitCode, 'output' => implode("\n", $output)];
}

/* ------------------------------ 1. نسخ نظيف ------------------------------ */
say('١) تجهيز مجلد تثبيت نظيف…');

if (is_dir($workDir)) {
    exec('rmdir /s /q "' . $workDir . '" 2>nul');
}
mkdir($workDir, 0775, true);

// نأخذ الملفات من git إن أمكن (يمثّل ما سينسخه المستخدم)
$tar    = $workDir . '/source.tar';
$gitOut = $workDir . '/source-git';
mkdir($gitOut, 0775, true);
$archive = run('cd "' . $root . '" && git archive --format=tar -o "' . $tar . '" HEAD');
if ($archive['code'] === 0 && is_file($tar) && filesize($tar) > 100) {
    run('tar -xf "' . $tar . '" -C "' . $workDir . '"');
    @unlink($tar);
    @rmdir($gitOut);
    say('   المصدر: نسخة من git (HEAD) — ' . count(glob($workDir . '/*') ?: []) . ' عنصر');
} else {
    @unlink($tar);
    @rmdir($gitOut);
    // بديل: نسخ يدوي مع استثناء ملفات التشغيل
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $copied = 0;
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if (preg_match('#(\.git|storage[\\\\/](config|database|uploads|backups|logs|tmp)|app[\\\\/]uploads[\\\\/]products)#', $path)) {
            continue;
        }
        $relative = substr($path, strlen($root) + 1);
        $target   = $workDir . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            @mkdir(dirname($target), 0775, true);
            copy($path, $target);
            $copied++;
        }
    }
    say("   المصدر: نسخ يدوي ($copied ملف)");
}

// نتأكد أن لا أسرار ولا قاعدة بيانات
$forbidden = [
    'storage/config/local.php',
    'storage/database/app.sqlite',
];
$leaked = [];
foreach ($forbidden as $file) {
    if (file_exists($workDir . '/' . $file)) {
        $leaked[] = $file;
    }
}
if ($leaked !== []) {
    say('   ✗ تسريب ملفات تشغيل: ' . implode(', ', $leaked));
    exit(1);
}
say('   ✓ لا قاعدة بيانات ولا إعدادات موروثة');

/* ------------------------------ 2. التهيئة ------------------------------ */
say('٢) تهيئة النسخة…');
$install = run('php "' . $workDir . '/bin/console" install --url=http://127.0.0.1:' . $port . ' --user=admin --pass=' . $password . ' 2>&1');
echo $install['output'] . "\n";
if ($install['code'] !== 0) {
    say('   ✗ فشل التثبيت');
    exit(1);
}

$icons = run('php "' . $workDir . '/bin/console" icons 2>&1');
say($icons['code'] === 0 ? '   ✓ الأيقونات' : '   ✗ الأيقونات');

$status = run('php "' . $workDir . '/bin/console" status 2>&1');
echo $status['output'] . "\n";

/* ------------------------------ 3. الخادم ------------------------------ */
say('٣) تشغيل الخادم على المنفذ ' . $port . '…');

// بيانات تجريبية حتى يعمل فحص الطلبات على نسخة نظيفة
$seed = run('php "' . $workDir . '/bin/console" seed:demo 2>&1');
say('   ' . (str_contains($seed['output'], 'منتج') ? '✓ بيانات تجريبية' : '⚠ لم تُضف بيانات تجريبية'));

// تشغيل الخادم كعملية مستقلة (stdout/stderr إلى ملف لتفادي الانتظار)
$descriptors = [
    0 => ['file', 'NUL', 'r'],
    1 => ['file', $workDir . '/server.log', 'a'],
    2 => ['file', $workDir . '/server.log', 'a'],
];
$serverProcess = proc_open(
    '"' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' "' . $workDir . '/bin/serve.php"',
    $descriptors,
    $pipes
);
if (!is_resource($serverProcess)) {
    say('   ✗ تعذّر تشغيل الخادم');
    exit(1);
}
$serverStatus = proc_get_status($serverProcess);
$pid          = (int) ($serverStatus['pid'] ?? 0);

$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(300000);
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/api/index.php?r=ping', false, stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]));
    if ($probe !== false) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    say('   ✗ الخادم لم يستجب');
    exit(1);
}
say('   ✓ الخادم يعمل (pid ' . $pid . ')');

/* ------------------------------ 4. الفحص ------------------------------ */
say('٤) فحص النشر على النسخة النظيفة…');
$check  = run('php "' . $workDir . '/tests/http/check.php" http://127.0.0.1:' . $port . ' 2>&1');
$passed = substr_count($check['output'], "\u{2713}");
$failed = substr_count($check['output'], "\u{2717}");
echo preg_replace('/\033\[[0-9;]*m/', '', $check['output']) . "\n";
say("   النتيجة: نجح $passed — فشل $failed");

/* ------------------------------ 5. التنظيف ------------------------------ */
if (is_resource($serverProcess)) {
    // على ويندوز تُشغَّل العملية عبر cmd، لذا نقتل شجرة العمليات كاملة
    if ($pid > 0) {
        exec('taskkill /PID ' . $pid . ' /T /F > nul 2>&1');
    }
    foreach ($pipes ?? [] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
}
exec('rmdir /s /q "' . $workDir . '" 2>nul');

exit(($failed === 0 && $install['code'] === 0) ? 0 : 1);