<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core.php';
$count = 0;
function check(bool $value, string $label): void {
    global $count;
    if (!$value) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $count++;
}
function rejects(Closure $fn, int $http = 400): void {
    try { $fn(); } catch (AppError $e) { check($e->http === $http, 'expected rejection'); return; }
    check(false, 'missing rejection');
}
check(validatePrompt('  سيارة فاخرة  ') === 'سيارة فاخرة', 'Arabic trim');
rejects(fn() => validatePrompt('   '));
rejects(fn() => validatePrompt([]));
check(strlen(validatePrompt(str_repeat('😀', 500))) > 0, 'UTF-16 boundary');
rejects(fn() => validatePrompt(str_repeat('😀', 501)));
rejects(fn() => validatePrompt("\xff"));
check(parseRange(null, 100) === [0, 99, false], 'full');
check(parseRange('bytes=10-20', 100) === [10, 20, true], 'range');
check(parseRange('bytes=90-', 100) === [90, 99, true], 'open range');
check(parseRange('bytes=-10', 100) === [90, 99, true], 'suffix');
check(parseRange('bytes=90-999', 100) === [90, 99, true], 'clamped');
rejects(fn() => parseRange('bytes=100-', 100), 416);
rejects(fn() => parseRange('bytes=-0', 100), 416);
rejects(fn() => parseRange('bytes=0-1,4-5', 100), 416);
$temp = sys_get_temp_dir() . '/mask-test-' . bin2hex(random_bytes(5));
mkdir($temp);
$store = new TaskStore($temp);
$id = str_repeat('a', 32);
$store->write(['id' => $id, 'owner' => 'alice', 'prompt' => 'عطر', 'status' => 'PENDING']);
check($store->read($id, 'alice')['prompt'] === 'عطر', 'persisted Unicode');
rejects(fn() => $store->read($id, 'bob'), 404);
rejects(fn() => $store->read('../outside', 'alice'));
rejects(fn() => downloadVideo('http://example.org/file.mp4', $temp . '/video.mp4'), 502);
rejects(fn() => downloadVideo('https://127.0.0.1/file.mp4', $temp . '/video.mp4'), 502);
rejects(fn() => downloadVideo('https://user:pass@example.org/file.mp4', $temp . '/video.mp4'), 502);
rejects(fn() => downloadVideo('https://example.org:123/file.mp4', $temp . '/video.mp4'), 502);
unlink($store->path($id)); rmdir($temp);
echo "PASS: $count checks; no paid API requests.\n";
