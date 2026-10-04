<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { throw new AppError('طريقة الطلب غير مسموحة.', 405); }
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$task = $store->read($id, $owner);
if (!in_array($task['status'], ['READY', 'FAILED', 'CANCELLED', 'CANCELED', 'SUBMISSION_UNKNOWN', 'NEEDS_REVIEW', 'SAVE_FAILED'], true)
    && !$client->active($id)) {
    // حالة عرض فقط؛ تجنب الكتابة المتزامنة فوق سجل العامل أو أي محاولة إرسال جديدة.
    $task = $store->read($id, $owner);
    if (!in_array($task['status'], ['READY', 'FAILED', 'CANCELLED', 'CANCELED', 'SAVE_FAILED'], true)) {
        $task['status'] = empty($task['provider_task_id']) ? 'SUBMISSION_UNKNOWN' : 'NEEDS_REVIEW';
        $task['message'] = empty($task['provider_task_id'])
            ? 'لم يتم تسجيل معرّف المزود. راجع لوحة Runway قبل إنشاء طلب آخر.'
            : 'شغّل خدمة SDK ثم تابع نفس الطلب لاسترجاع النتيجة دون توليد جديد.';
    }
}
respond(publicTask($task));
