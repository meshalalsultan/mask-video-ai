<?php
declare(strict_types=1);
// انسخ الملف باسم config.local.php وضع مفتاحك على جهازك فقط.
return [
    'api_key' => '',
    'sdk_port' => 8787,
    'php_cli' => PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''),
    'model' => 'gen4.5',
    'duration' => 5,
    'ratio' => '720:1280',
    'storage_dir' => __DIR__ . '/storage',
];
