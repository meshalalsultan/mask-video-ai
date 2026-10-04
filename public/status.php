<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { throw new AppError('طريقة الطلب غير مسموحة.', 405); }
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$task = $store->read($id, $owner);
if (in_array($task['status'], ['READY', 'FAILED', 'CANCELED', 'SUBMISSION_UNKNOWN'], true)) {
    respond(publicTask($task));
}
if (empty($task['provider_task_id'])) {
    $task['status'] = 'SUBMISSION_UNKNOWN';
    $task['message'] = 'لم يتم تسجيل معرّف المزود. راجع لوحة Runway قبل إنشاء طلب آخر.';
    $store->write($task);
    respond(publicTask($task));
}
requireKey();
// لا نستطلع المزود أسرع من مرة كل 5 ثوانٍ لكل طلب.
if (time() - ($task['last_checked'] ?? 0) < 5) { respond(publicTask($task)); }
$result = $client->status($task['provider_task_id']);
$task['last_checked'] = time();
$task['status'] = $result['status'];
if ($result['status'] === 'SUCCEEDED') {
    $task['status'] = 'SAVING';
    $store->write($task);
    $url = $result['output'][0] ?? null;
    if (!is_string($url)) { throw new AppError('لا يوجد رابط فيديو في نتيجة المزود.', 502); }
    downloadVideo($url, $config['storage_dir'] . '/videos/' . $id . '.mp4');
    $task['status'] = 'READY';
    $task['completed_at'] = gmdate('c');
} elseif (in_array($result['status'], ['FAILED', 'CANCELED'], true)) {
    $task['message'] = $result['status'] === 'FAILED' ? 'لم يكتمل التوليد. راجع لوحة Runway لمعرفة سبب الفشل.' : 'ألغي الطلب لدى المزود.';
}
$store->write($task);
respond(publicTask($task));
