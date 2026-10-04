<?php
declare(strict_types=1);
require_once __DIR__ . '/Core.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(180);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; media-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
set_exception_handler(static function (Throwable $error): void {
    respond(['error' => $error instanceof AppError ? $error->getMessage() : 'حدث خطأ داخلي. راجع سجل PHP.'],
        $error instanceof AppError ? $error->http : 500);
});
// النموذج الأولي لجهازك فقط. لا توجد حسابات مستخدمين أو صلاحيات نشر عام بعد.
$host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
    throw new AppError('هذه النسخة تعمل محليًا على جهاز صاحب المشروع فقط.', 403);
}
foreach (['curl', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) { throw new AppError('فعّل إضافة PHP: ' . $extension, 503); }
}
$config = require dirname(__DIR__) . '/config.example.php';
$local = dirname(__DIR__) . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (!is_array($override)) { throw new AppError('ملف الإعدادات غير صالح.', 503); }
    $config = array_replace($config, $override);
}
$config['api_key'] = getenv('RUNWAYML_API_SECRET') ?: (getenv('RUNWAY_API_KEY') ?: $config['api_key']);
if ($config['model'] !== 'gen4.5' || !in_array($config['duration'], [5, 10], true)
    || !in_array($config['ratio'], ['720:1280', '1280:720'], true)) {
    throw new AppError('إعدادات النموذج غير مدعومة في هذه النسخة.', 503);
}
if (!is_int($config['sdk_port']) || $config['sdk_port'] < 1024 || $config['sdk_port'] > 65535) {
    throw new AppError('منفذ خدمة SDK غير صالح.', 503);
}
foreach (['tasks', 'videos'] as $folder) {
    $directory = $config['storage_dir'] . '/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) { throw new AppError('تعذر إنشاء التخزين.', 503); }
    if (!is_writable($directory)) { throw new AppError('مجلد التخزين غير قابل للكتابة.', 503); }
}
ini_set('session.use_strict_mode', '1');
session_name('mask_video_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['tasks'] ??= [];
$owner = hash('sha256', session_id());
$store = new TaskStore($config['storage_dir'] . '/tasks');
$client = new SdkBridge($config);

function requirePost(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new AppError('طريقة الطلب غير مسموحة.', 405); }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $csrf)) { throw new AppError('حدّث الصفحة وحاول مجددًا.', 403); }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 10000) { throw new AppError('الطلب كبير جدًا.', 413); }
    $raw = file_get_contents('php://input', false, null, 0, 10001);
    if (strlen($raw ?: '') > 10000) { throw new AppError('الطلب كبير جدًا.', 413); }
    try { $body = json_decode($raw ?: '', true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new AppError('صيغة الطلب غير صالحة.'); }
    if (!is_array($body)) { throw new AppError('صيغة الطلب غير صالحة.'); }
    return $body;
}

function requireKey(): void
{
    global $config;
    if (trim($config['api_key']) === '') { throw new AppError('أضف RUNWAYML_API_SECRET لبيئة PHP أو مفتاح Runway في config.local.php أولًا.', 503); }
}

function publicTask(array $task): array
{
    $ready = $task['status'] === 'READY';
    return ['id' => $task['id'], 'status' => $task['status'], 'prompt' => $task['prompt'],
        'created_at' => $task['created_at'], 'provider_task_id' => $task['provider_task_id'] ?? null,
        'message' => $task['message'] ?? null,
        'video_url' => $ready ? 'media.php?id=' . $task['id'] : null,
        'download_url' => $ready ? 'media.php?id=' . $task['id'] . '&download=1' : null];
}
