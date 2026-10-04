<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) { throw new AppError('طريقة الطلب غير مسموحة.', 405); }
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$task = $store->read($id, $owner);
$path = $config['storage_dir'] . '/videos/' . $id . '.mp4';
if ($task['status'] !== 'READY' || !is_file($path)) { throw new AppError('الفيديو غير جاهز.', 404); }
$size = filesize($path);
try { [$start, $end, $partial] = parseRange($_SERVER['HTTP_RANGE'] ?? null, $size); }
catch (AppError $e) { header('Content-Range: bytes */' . $size); throw $e; }
session_write_close();
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="video-' . $id . '.mp4"');
if ($partial) { http_response_code(206); header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size); }
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { exit; }
$file = fopen($path, 'rb');
if (!$file) { throw new AppError('تعذر قراءة الفيديو.', 500); }
fseek($file, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($file) && !connection_aborted()) {
    $chunk = fread($file, min(65536, $remaining));
    if ($chunk === false || $chunk === '') { break; }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($file);
