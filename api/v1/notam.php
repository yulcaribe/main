<?php
declare(strict_types=1);

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));

// Keep the proven map geometry path during the migration, but expose it through
// the canonical NOTAM API. The map client no longer needs to know navmap.php.
if ($action === 'map') {
    $_GET['action'] = 'viewport';
    $_GET['layers'] = 'notam';
    require dirname(__DIR__) . '/navmap.php';
    exit;
}

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/notam/core.php';

ycApiV1Headers($action === 'filters' ? 'public, max-age=300, stale-while-revalidate=600' : 'no-store, max-age=0');
ycApiV1Method('GET');

try {
    $pdo = nmsDb();

    if ($action === 'detail') {
        $id = ycApiV1String($_GET, 'id', 160);
        if ($id === '') {
            ycApiV1Respond(400, ['ok' => false, 'error' => 'NOTAM id gerekli.']);
        }

        $notam = ycNotamDetail($pdo, $id);
        if ($notam === null) {
            ycApiV1Respond(404, ['ok' => false, 'error' => 'NOTAM bulunamadı.']);
        }

        ycApiV1Respond(200, [
            'ok' => true,
            'resource' => 'notams',
            'mode' => 'detail',
            'source' => YC_NOTAM_SOURCE,
            'environment' => YC_NOTAM_ENVIRONMENT,
            'retentionDays' => YC_NOTAM_RETENTION_DAYS,
            'generatedAt' => gmdate('c'),
            'notam' => $notam,
        ]);
    }

    if ($action === 'filters') {
        ycApiV1Respond(200, [
            'ok' => true,
            'resource' => 'notams',
            'mode' => 'filters',
            'source' => YC_NOTAM_SOURCE,
            'environment' => YC_NOTAM_ENVIRONMENT,
            'retentionDays' => YC_NOTAM_RETENTION_DAYS,
            'generatedAt' => gmdate('c'),
            'options' => ycNotamFilterOptions($pdo),
            'states' => ['valid', 'future', 'expired', 'cancelled', 'all'],
            'sorts' => ['updated_desc', 'start_desc', 'start_asc', 'ident'],
        ]);
    }

    if ($action !== 'list') {
        ycApiV1Respond(400, [
            'ok' => false,
            'error' => 'Geçersiz action. Kullanılabilir: list, detail, filters, map.',
        ]);
    }

    $result = ycNotamList($pdo, $_GET);
    $returned = count($result['items']);
    $total = (int)$result['sqlTotal'];
    $limit = (int)$result['limit'];
    $page = (int)$result['page'];
    $pages = $total > 0 ? (int)ceil($total / $limit) : 0;

    ycApiV1Respond(200, [
        'ok' => true,
        'resource' => 'notams',
        'mode' => 'list',
        'source' => YC_NOTAM_SOURCE,
        'environment' => YC_NOTAM_ENVIRONMENT,
        'retentionDays' => YC_NOTAM_RETENTION_DAYS,
        'generatedAt' => gmdate('c'),
        'atUtc' => $result['atUtc'],
        'state' => $result['state'],
        'filters' => $result['filters'],
        'paging' => [
            'page' => $page,
            'limit' => $limit,
            'returned' => $returned,
            'total' => $total,
            'pages' => $pages,
            'hasPrevious' => $page > 1,
            'hasNext' => $pages > 0 && $page < $pages,
        ],
        'items' => $result['items'],
    ]);
} catch (Throwable $e) {
    ycApiV1Respond(500, [
        'ok' => false,
        'error' => 'NOTAM API isteği işlenemedi.',
    ]);
}
