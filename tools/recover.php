<?php
declare(strict_types=1);
// أداة يدوية بعد فحص لوحة Runway فقط. لا تبدأ أي توليد جديد.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Core.php';
$config = require dirname(__DIR__) . '/config.example.php';
if (is_file(dirname(__DIR__) . '/config.local.php')) {
    $config = array_replace($config, require dirname(__DIR__) . '/config.local.php');
}
$id = $argv[1] ?? '';
$providerId = $argv[2] ?? '';
$store = new TaskStore($config['storage_dir'] . '/tasks');
try {
    $path = $store->path($id);
    if (!is_file($path)) { throw new RuntimeException('الطلب غير موجود.'); }
    $task = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($task['status'], ['SUBMISSION_UNKNOWN', 'SUBMITTING'], true)) {
        throw new RuntimeException('الطلب ليس في حالة إرسال غير مؤكدة.');
    }
    if ($providerId === '--not-submitted') {
        $task['status'] = 'FAILED';
        $task['message'] = 'أكد صاحب المشروع من لوحة Runway أن الطلب لم يبدأ.';
    } elseif (preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $providerId)) {
        $task['provider_task_id'] = $providerId;
        $task['status'] = 'PENDING';
        unset($task['message']);
    } else { throw new RuntimeException('أدخل رقم الطلب المحلي ثم رقم Runway أو --not-submitted.'); }
    $store->write($task);
    echo "تم تحديث الحالة. حدّث صفحة التطبيق في نفس جلسة المتصفح.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
