<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Core.php';
try {
    $input = json_decode((string) stream_get_contents(STDIN, 16384), true, 512, JSON_THROW_ON_ERROR);
    if (!is_string($input['url'] ?? null) || !is_string($input['destination'] ?? null)) { exit(1); }
    downloadVideo($input['url'], $input['destination']);
} catch (Throwable) { exit(1); }
