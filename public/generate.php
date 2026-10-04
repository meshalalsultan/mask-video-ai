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
    if (!in_array($task['status'], ['READY', 'FAILED', 'CANCELED'], true)) {
        throw new AppError('يوجد طلب سابق قيد المتابعة. أكمله أو راجع حالته في لوحة Runway أولًا.', 409);
    }
}
if (count($_SESSION['tasks']) >= 30) { throw new AppError('وصلت إلى حد التجارب لهذه الجلسة.', 429); }
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
    $task['provider_task_id'] = $client->create($prompt);
    $task['status'] = 'PENDING';
} catch (Throwable $error) {
    // لا نكرر POST تلقائيًا: timeout أو 5xx لا يثبتان أن التوليد لم يبدأ.
    $task['status'] = ($error instanceof AppError && !$error->uncertain) ? 'FAILED' : 'SUBMISSION_UNKNOWN';
    $task['message'] = ($error instanceof AppError ? $error->getMessage() : 'تعذر تأكيد إرسال الطلب.')
        . ($task['status'] === 'SUBMISSION_UNKNOWN' ? ' راجع لوحة Runway قبل بدء تجربة أخرى.' : '');
    $store->write($task);
    respond(publicTask($task));
}
$store->write($task);
respond(publicTask($task), 202);
