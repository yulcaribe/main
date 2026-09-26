<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Canonical cron entry point. The NMS sync engine remains isolated while its
// storage/auth internals are consolidated in a later safe migration.
require dirname(__DIR__) . '/notam/nms/cron.php';
