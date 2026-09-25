<?php
declare(strict_types=1);

/**
 * Deprecated compatibility shim.
 *
 * Canonical NOTAM reads live under /main/api/v1/notam.php. Keep this path only
 * so an older client does not fall off a cliff while the Android/web migration
 * is in progress. No NOTAM business logic is duplicated here.
 */
$_GET['action'] = 'list';
$_GET['state'] = $_GET['state'] ?? 'valid';
$_GET['limit'] = $_GET['limit'] ?? '200';
$_GET['include_text'] = $_GET['include_text'] ?? '1';

require __DIR__ . '/v1/notam.php';
