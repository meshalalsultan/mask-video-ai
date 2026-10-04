<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$body = requirePost();
requireKey();
$prompt = validatePrompt($body['prompt'] ?? null);
$id = is_string($body['request_id'] ?? null) ? $body['request_id'] : '';
if (!validId($id)) { throw new AppError('معرّف الطلب غير صالح.'); }
// جلسة PHP تبقى مقفلة خلال الإرسال: طلبان متزامنان لا يبدآن عمليتين مدفوعتين.
if (in_array($id, $_SESSION['tasks'], true)) {
    $existing = $store->read($id, $owner);
    if ($existing['prompt'] !== $prompt) { throw new AppError('المعرّف مستخدم لوصف آخر.', 409); }
    respond(publicTask($existing));
}
foreach ($_SESSION['tasks'] as $previous) {
    $task = $store->read($previous, $owner);
    if (!in_array($task['status'], ['READY', 'FAILED', 'CANCELLED', 'CANCELED'], true)) {
        throw new AppError('يوجد طلب سابق قيد المتابعة. أكمله أو راجع حالته في لوحة Runway أولًا.', 409);
    }
}
if (count($_SESSION['tasks']) >= 30) { throw new AppError('وصلت إلى حد التجارب لهذه الجلسة.', 429); }
if (!$client->health()) { throw new AppError('شغّل خدمة SDK باستخدام npm start قبل التوليد.', 503); }
$task = ['id' => $id, 'owner' => $owner, 'prompt' => $prompt, 'status' => 'SUBMITTING',
    'created_at' => gmdate('c'), 'model' => $config['model'], 'duration' => $config['duration'],
    'ratio' => $config['ratio'], 'api_cost_actual' => null];
$store->write($task);
$_SESSION['tasks'][] = $id;
// تثبيت الجلسة على القرص قبل بدء الطلب المدفوع، مع إبقاء قفلها بعد إعادة فتحها.
$sessionId = session_id();
session_write_close();
session_id($sessionId);
session_start();
try {
    $client->start($id);
} catch (Throwable $error) {
    // الخدمة قد تكون استلمت الطلب رغم انقطاع الاتصال. لا نكتب فوق تحديث العامل.
    $task = $store->read($id, $owner);
    if ($task['status'] === 'SUBMITTING') {
        $task['status'] = 'SUBMISSION_UNKNOWN';
        $task['message'] = 'تعذر تأكيد الإرسال. راجع لوحة Runway قبل إنشاء طلب آخر.';
        $store->write($task);
    }
    respond(publicTask($task));
}
respond(publicTask($store->read($id, $owner)), 202);
