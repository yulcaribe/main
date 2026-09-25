<?php
declare(strict_types=1);

require_once __DIR__ . '/full_run.php';

function nmsHealthLocal(string $environment): array {
    $pdo = nmsDb();
    $state = nmsSyncState($pdo, $environment);

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'cancelled') AS cancelled,
            SUM(status <> 'cancelled' AND effective_start > UTC_TIMESTAMP()) AS future_count,
            SUM(status <> 'cancelled' AND effective_end IS NOT NULL AND effective_end < UTC_TIMESTAMP()) AS expired,
            SUM(
                status <> 'cancelled'
                AND (effective_start IS NULL OR effective_start <= UTC_TIMESTAMP())
                AND (effective_end IS NULL OR effective_end >= UTC_TIMESTAMP())
                AND (
                    effective_start IS NOT NULL
                    OR effective_end IS NOT NULL
                    OR UPPER(COALESCE(effective_end_raw, '')) = 'PERM'
                )
            ) AS active,
            SUM(
                status <> 'cancelled'
                AND effective_start IS NULL
                AND effective_end IS NULL
                AND UPPER(COALESCE(effective_end_raw, '')) <> 'PERM'
            ) AS unknown_count,
            SUM(UPPER(COALESCE(effective_end_raw, '')) = 'PERM') AS permanent_count,
            SUM(geometry IS NOT NULL) AS with_geometry,
            MAX(last_updated) AS latest_notam_update
         FROM notams
         WHERE source = 'FAA_NMS' AND environment = :environment"
    );
    $stmt->execute(['environment' => $environment]);
    $raw = $stmt->fetch() ?: [];

    $classStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(classification, ''), 'UNKNOWN') AS classification, COUNT(*) AS total
         FROM notams
         WHERE source = 'FAA_NMS' AND environment = :environment
         GROUP BY COALESCE(NULLIF(classification, ''), 'UNKNOWN')
         ORDER BY total DESC, classification"
    );
    $classStmt->execute(['environment' => $environment]);

    $classifications = [];
    foreach ($classStmt as $row) {
        $classifications[] = [
            'classification' => (string)$row['classification'],
            'total' => (int)$row['total'],
        ];
    }

    $environmentCounts = [
        'staging' => 0,
        'production' => 0,
        'other' => 0,
    ];
    $envStmt = $pdo->query(
        "SELECT COALESCE(NULLIF(environment, ''), 'unknown') AS environment, COUNT(*) AS total
         FROM notams
         WHERE source = 'FAA_NMS'
         GROUP BY COALESCE(NULLIF(environment, ''), 'unknown')"
    );
    foreach ($envStmt as $row) {
        $name = strtolower((string)$row['environment']);
        $count = (int)$row['total'];
        if ($name === 'staging' || $name === 'production') {
            $environmentCounts[$name] = $count;
        } else {
            $environmentCounts['other'] += $count;
        }
    }

    $total = (int)($raw['total'] ?? 0);
    $withGeometry = (int)($raw['with_geometry'] ?? 0);
    $syncAgeSeconds = null;

    if (!empty($state['last_successful_sync'])) {
        $ts = strtotime((string)$state['last_successful_sync'] . ' UTC');
        if ($ts !== false) $syncAgeSeconds = max(0, time() - $ts);
    }

    $syncHealth = 'never';
    if ($syncAgeSeconds !== null) {
        if ($syncAgeSeconds <= 420) $syncHealth = 'ok';
        elseif ($syncAgeSeconds <= 900) $syncHealth = 'warning';
        else $syncHealth = 'stale';
    }

    $cronState = null;
    $cronPath = nmsCacheDir() . DIRECTORY_SEPARATOR . 'cron_state_' . $environment . '.json';
    if (is_file($cronPath)) {
        $cronRaw = @file_get_contents($cronPath);
        $cronJson = is_string($cronRaw) ? json_decode($cronRaw, true) : null;
        if (is_array($cronJson)) $cronState = $cronJson;
    }

    $cacheDir = nmsCacheDir();
    $progressPath = nmsFullProgressPath($environment);
    $progress = nmsFullReadProgress($environment);
    $fullLoadDiagnostics = [
        'cacheDir' => $cacheDir,
        'progressFile' => basename($progressPath),
        'progressExists' => is_file($progressPath),
        'snapshotFile' => null,
        'snapshotBytes' => null,
        'snapshotModifiedAt' => null,
        'downloadFile' => null,
        'downloadBytes' => null,
        'byteOffset' => null,
        'processed' => null,
        'skipped' => null,
        'expected' => null,
        'startedAt' => null,
        'updatedAt' => null,
        'source' => null,
    ];

    if (is_array($progress)) {
        $xmlPath = (string)($progress['xmlPath'] ?? '');
        $downloadPath = (string)($progress['downloadPath'] ?? '');
        $xmlMtime = $xmlPath !== '' && is_file($xmlPath) ? @filemtime($xmlPath) : false;

        $fullLoadDiagnostics['snapshotFile'] = $xmlPath !== '' ? basename($xmlPath) : null;
        $fullLoadDiagnostics['snapshotBytes'] = $xmlPath !== '' && is_file($xmlPath) ? (int)@filesize($xmlPath) : null;
        $fullLoadDiagnostics['snapshotModifiedAt'] = $xmlMtime !== false ? gmdate('Y-m-d\\TH:i:s\\Z', $xmlMtime) : null;
        $fullLoadDiagnostics['downloadFile'] = $downloadPath !== '' ? basename($downloadPath) : null;
        $fullLoadDiagnostics['downloadBytes'] = $downloadPath !== '' && is_file($downloadPath) ? (int)@filesize($downloadPath) : null;
        $fullLoadDiagnostics['byteOffset'] = isset($progress['byteOffset']) ? (int)$progress['byteOffset'] : null;
        $fullLoadDiagnostics['processed'] = isset($progress['processed']) ? (int)$progress['processed'] : null;
        $fullLoadDiagnostics['skipped'] = isset($progress['skipped']) ? (int)$progress['skipped'] : null;
        $fullLoadDiagnostics['expected'] = isset($progress['expected']) ? (int)$progress['expected'] : null;
        $fullLoadDiagnostics['startedAt'] = $progress['startedAt'] ?? null;
        $fullLoadDiagnostics['updatedAt'] = $progress['updatedAt'] ?? null;
        $fullLoadDiagnostics['source'] = $progress['source'] ?? null;
    }

    $retentionPath = nmsCacheDir() . DIRECTORY_SEPARATOR
        . 'retention_' . preg_replace('/[^a-z0-9_-]+/i', '_', $environment)
        . '_3d.json';
    $retentionCleanup = null;
    if (is_file($retentionPath)) {
        $retentionRaw = @file_get_contents($retentionPath);
        $retentionDecoded = json_decode((string)$retentionRaw, true);
        if (is_array($retentionDecoded)) $retentionCleanup = $retentionDecoded;
    }

    return [
        'state' => $state,
        'syncHealth' => $syncHealth,
        'syncAgeSeconds' => $syncAgeSeconds,
        'counts' => [
            'total' => $total,
            'active' => (int)($raw['active'] ?? 0),
            'future' => (int)($raw['future_count'] ?? 0),
            'expired' => (int)($raw['expired'] ?? 0),
            'cancelled' => (int)($raw['cancelled'] ?? 0),
            'unknown' => (int)($raw['unknown_count'] ?? 0),
            'permanent' => (int)($raw['permanent_count'] ?? 0),
            'withGeometry' => $withGeometry,
            'withoutGeometry' => max(0, $total - $withGeometry),
        ],
        'classifications' => $classifications,
        'environmentCounts' => $environmentCounts,
        'latestNotamUpdate' => $raw['latest_notam_update'] ?: null,
        'cronState' => $cronState,
        'retentionCleanup' => $retentionCleanup,
        'fullLoadDiagnostics' => $fullLoadDiagnostics,
        'extensions' => [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'curl' => extension_loaded('curl'),
            'xmlreader' => class_exists(XMLReader::class),
            'dom' => class_exists(DOMDocument::class),
            'zlib' => extension_loaded('zlib'),
            'zip' => class_exists(ZipArchive::class),
        ],
    ];
}
