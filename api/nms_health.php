<?php
declare(strict_types=1);

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
        'latestNotamUpdate' => $raw['latest_notam_update'] ?: null,
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
