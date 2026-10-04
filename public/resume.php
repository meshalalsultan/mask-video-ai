<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$body = requirePost();
requireKey();
$id = is_string($body['id'] ?? null) ? $body['id'] : '';
$task = $store->read($id, $owner);
if (empty($task['provider_task_id']) || in_array($task['status'], ['READY', 'FAILED', 'CANCELLED', 'CANCELED'], true)) {
    throw new AppError('هذا الطلب لا يحتاج استئنافًا أو يتطلب مراجعة لوحة Runway أولًا.', 409);
}
$client->start($id, true);
// SDK يسترجع معرّف المزود نفسه؛ لا يستدعي إنشاء فيديو جديد.
respond(publicTask($store->read($id, $owner)), 202);
