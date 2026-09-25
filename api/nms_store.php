<?php
declare(strict_types=1);

/**
 * Local FAA NMS NOTAM store and delta-sync engine.
 *
 * This file never exposes credentials. FAA data is normalized into MariaDB and
 * Pilot Briefing should query the local database rather than FAA directly.
 */

function nmsDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PDO MySQL is not enabled.');
    }

    $homeRoot = dirname(dirname(dirname(__DIR__)));
    $configPath = $homeRoot . '/data.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Database configuration was not found.');
    }

    $cfg = require $configPath;
    if (!is_array($cfg)) {
        throw new RuntimeException('Database configuration is invalid.');
    }

    foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
        if (!array_key_exists($key, $cfg)) {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['database']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function nmsStoreText(mixed $value): ?string {
    if ($value === null) return null;
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_scalar($value)) {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function nmsStoreShortText(mixed $value, int $maxLength): ?string {
    $text = nmsStoreText($value);
    if ($text === null) return null;
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength)
        : substr($text, 0, $maxLength);
}

function nmsStoreDate(mixed $value): ?string {
    $raw = nmsStoreText($value);
    if ($raw === null || strtoupper($raw) === 'PERM') return null;

    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function nmsStoreInt(mixed $value): ?int {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (int)$value;

    $text = nmsStoreText($value);
    if ($text !== null && preg_match('/-?\d+/', $text, $m)) {
        return (int)$m[0];
    }
    return null;
}

function nmsStoreFloat(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (float)$value;
    return null;
}

function nmsCanonicalClassification(mixed $value): ?string {
    $text = strtoupper(trim((string)($value ?? '')));
    if ($text === '') return null;

    return match ($text) {
        'DOM' => 'DOMESTIC',
        'INTL' => 'INTERNATIONAL',
        'MIL' => 'MILITARY',
        'LMIL', 'LOCAL_MIL' => 'LOCAL_MILITARY',
        default => $text,
    };
}

function nmsRecordStatus(?string $type, ?string $effectiveStart, ?string $effectiveEndRaw): string {
    if (strtoupper((string)$type) === 'C') return 'cancelled';

    $now = time();
    $start = $effectiveStart !== null ? strtotime($effectiveStart . ' UTC') : false;
    $endRaw = strtoupper(trim((string)$effectiveEndRaw));
    $end = ($endRaw !== '' && $endRaw !== 'PERM') ? strtotime((string)$effectiveEndRaw) : false;

    if ($start !== false && $start > $now) return 'inactive';
    if ($end !== false && $end < $now) return 'inactive';
    if ($start !== false || $endRaw === 'PERM' || $end !== false) return 'active';
    return 'unknown';
}

function nmsNormalizeFeature(array $feature, string $environment): ?array {
    $notam = $feature['properties']['coreNOTAMData']['notam'] ?? null;
    if (!is_array($notam)) return null;

    $nmsId = trim((string)($notam['id'] ?? ''));
    if ($nmsId === '') return null;

    $effectiveStartRaw = nmsStoreText($notam['effectiveStart'] ?? null);
    $effectiveEndRaw = nmsStoreText($notam['effectiveEnd'] ?? null);
    $effectiveStart = nmsStoreDate($effectiveStartRaw);
    $effectiveEnd = nmsStoreDate($effectiveEndRaw);
    $type = nmsStoreShortText($notam['type'] ?? null, 10);

    $geometryJson = null;
    if (isset($feature['geometry']) && is_array($feature['geometry'])) {
        $encoded = json_encode($feature['geometry'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) $geometryJson = $encoded;
    }

    $rawJson = json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawJson === false) $rawJson = null;

    return [
        'nms_id' => $nmsId,
        'series' => nmsStoreShortText($notam['series'] ?? null, 10),
        'number' => nmsStoreShortText($notam['number'] ?? null, 30),
        'year' => nmsStoreInt($notam['year'] ?? null),
        'notam_type' => $type,
        'classification' => nmsCanonicalClassification($notam['classification'] ?? null),
        'affected_fir' => nmsStoreShortText($notam['affectedFir'] ?? null, 20),
        'location' => nmsStoreShortText($notam['location'] ?? null, 20),
        'icao_location' => nmsStoreShortText($notam['icaoLocation'] ?? null, 20),
        'account_id' => nmsStoreShortText($notam['accountId'] ?? null, 50),
        'selection_code' => nmsStoreShortText($notam['selectionCode'] ?? null, 30),
        'traffic' => nmsStoreShortText($notam['traffic'] ?? null, 20),
        'purpose' => nmsStoreShortText($notam['purpose'] ?? null, 20),
        'scope' => nmsStoreShortText($notam['scope'] ?? null, 20),
        'minimum_fl' => nmsStoreInt($notam['minimumFl'] ?? null),
        'maximum_fl' => nmsStoreInt($notam['maximumFl'] ?? null),
        'effective_start' => $effectiveStart,
        'effective_end' => $effectiveEnd,
        'effective_end_raw' => nmsStoreShortText($effectiveEndRaw, 50),
        'estimated' => nmsStoreShortText($notam['estimated'] ?? null, 20),
        'schedule' => nmsStoreText($notam['schedule'] ?? null),
        'lower_limit' => nmsStoreText($notam['lowerLimit'] ?? null),
        'upper_limit' => nmsStoreText($notam['upperLimit'] ?? null),
        'coordinates_raw' => nmsStoreText($notam['coordinates'] ?? null),
        'radius_nm' => nmsStoreFloat($notam['radius'] ?? null),
        'notam_text' => nmsStoreText($notam['text'] ?? null),
        'last_updated' => nmsStoreDate($notam['lastUpdated'] ?? null),
        'status' => nmsRecordStatus($type, $effectiveStart, $effectiveEndRaw),
        'geometry_json' => $geometryJson,
        'raw_json' => $rawJson,
        'source' => 'FAA_NMS',
        'environment' => $environment,
    ];
}

function nmsUpsertRecord(PDO $pdo, array $r): void {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) {
        $stmt = $pdo->prepare(
            'INSERT INTO notams (
                nms_id, series, number, year, notam_type, classification,
                affected_fir, location, icao_location, account_id,
                selection_code, traffic, purpose, scope,
                minimum_fl, maximum_fl,
                effective_start, effective_end, effective_end_raw,
                estimated, schedule, lower_limit, upper_limit,
                coordinates_raw, radius_nm, notam_text, last_updated,
                status, geometry, raw_json, source, environment
            ) VALUES (
                :nms_id, :series, :number, :year, :notam_type, :classification,
                :affected_fir, :location, :icao_location, :account_id,
                :selection_code, :traffic, :purpose, :scope,
                :minimum_fl, :maximum_fl,
                :effective_start, :effective_end, :effective_end_raw,
                :estimated, :schedule, :lower_limit, :upper_limit,
                :coordinates_raw, :radius_nm, :notam_text, :last_updated,
                :status,
                NULL,
                :raw_json, :source, :environment
            )
            ON DUPLICATE KEY UPDATE
                series = VALUES(series),
                number = VALUES(number),
                year = VALUES(year),
                notam_type = VALUES(notam_type),
                classification = VALUES(classification),
                affected_fir = VALUES(affected_fir),
                location = VALUES(location),
                icao_location = VALUES(icao_location),
                account_id = VALUES(account_id),
                selection_code = VALUES(selection_code),
                traffic = VALUES(traffic),
                purpose = VALUES(purpose),
                scope = VALUES(scope),
                minimum_fl = VALUES(minimum_fl),
                maximum_fl = VALUES(maximum_fl),
                effective_start = VALUES(effective_start),
                effective_end = VALUES(effective_end),
                effective_end_raw = VALUES(effective_end_raw),
                estimated = VALUES(estimated),
                schedule = VALUES(schedule),
                lower_limit = VALUES(lower_limit),
                upper_limit = VALUES(upper_limit),
                coordinates_raw = VALUES(coordinates_raw),
                radius_nm = VALUES(radius_nm),
                notam_text = VALUES(notam_text),
                last_updated = VALUES(last_updated),
                status = VALUES(status),
                geometry = VALUES(geometry),
                raw_json = VALUES(raw_json),
                source = VALUES(source),
                environment = VALUES(environment)'
        );
    }

    $geometryJson = $r['geometry_json'] ?? null;
    $params = $r;
    unset($params['geometry_json']);
    $stmt->execute($params);

    if (is_string($geometryJson) && $geometryJson !== '') {
        try {
            $geomStmt = $pdo->prepare(
                'UPDATE notams
                 SET geometry = ST_GeomFromGeoJSON(:geometry_json)
                 WHERE nms_id = :nms_id'
            );
            $geomStmt->execute([
                'geometry_json' => $geometryJson,
                'nms_id' => $r['nms_id'],
            ]);
        } catch (Throwable) {
            // Keep the normalized NOTAM and raw GeoJSON even if one geometry
            // cannot be represented by this MariaDB build.
        }
    }
}

function nmsApplyCancellationReference(PDO $pdo, array $record): ?string {
    if (($record['status'] ?? '') !== 'cancelled') return null;
    $text = (string)($record['notam_text'] ?? '');
    if (!preg_match('/\\bNOTAMC\\s+([A-Z])([0-9]{4})\\/(\\d{2})\\b/i', $text, $m)) return null;

    $series = strtoupper($m[1]);
    $serial = $m[2];
    $year2 = (int)$m[3];
    $year4 = 2000 + $year2;
    $target = $series . $serial . '/' . $m[3];

    $stmt = $pdo->prepare(
        "UPDATE notams
         SET status = 'cancelled'
         WHERE source = 'FAA_NMS'
           AND environment = :environment
           AND series = :series
           AND (number = :serial OR number = :serial_slash OR number = :target)
           AND (year = :year4 OR year = :year2 OR year IS NULL)"
    );
    $stmt->execute([
        'environment' => (string)($record['environment'] ?? ''),
        'series' => $series,
        'serial' => $serial,
        'serial_slash' => $serial . '/' . $m[3],
        'target' => $target,
        'year4' => $year4,
        'year2' => $year2,
    ]);

    return $target;
}

function nmsEnsureSyncState(PDO $pdo, string $environment): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notam_sync_state (source, environment)
         VALUES (\'FAA_NMS\', :environment)
         ON DUPLICATE KEY UPDATE source = source'
    );
    $stmt->execute(['environment' => $environment]);
}

function nmsSyncState(PDO $pdo, string $environment): array {
    nmsEnsureSyncState($pdo, $environment);
    $stmt = $pdo->prepare(
        'SELECT source, environment, last_successful_sync, last_full_load,
                last_request_id, last_error, created_at, updated_at
         FROM notam_sync_state
         WHERE source = \'FAA_NMS\' AND environment = :environment'
    );
    $stmt->execute(['environment' => $environment]);
    return $stmt->fetch() ?: [];
}

function nmsLocalStatus(string $environment): array {
    $pdo = nmsDb();
    $state = nmsSyncState($pdo, $environment);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notams WHERE source = \'FAA_NMS\' AND environment = :environment');
    $countStmt->execute(['environment' => $environment]);

    $latestStmt = $pdo->prepare('SELECT MAX(last_updated) FROM notams WHERE source = \'FAA_NMS\' AND environment = :environment');
    $latestStmt->execute(['environment' => $environment]);

    return [
        'state' => $state,
        'notamCount' => (int)$countStmt->fetchColumn(),
        'latestNotamUpdate' => $latestStmt->fetchColumn() ?: null,
    ];
}


function nmsCleanupOldNotams(PDO $pdo, string $environment, int $retentionDays = 3, bool $force = false): array {
    $retentionDays = max(1, min(30, $retentionDays));
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionDays . ' days')
        ->format('Y-m-d H:i:s');

    // Shared-hosting friendly: the delta cron may run every few minutes, but
    // retention cleanup only needs to sweep periodically.
    $markerPath = nmsCacheDir() . DIRECTORY_SEPARATOR
        . 'retention_' . preg_replace('/[^a-z0-9_-]+/i', '_', $environment)
        . '_' . $retentionDays . 'd.json';

    if (!$force && is_file($markerPath)) {
        $age = time() - (int)@filemtime($markerPath);
        if ($age >= 0 && $age < 21600) {
            return [
                'ok' => true,
                'skipped' => true,
                'retentionDays' => $retentionDays,
                'cutoff' => $cutoff,
                'nextSweepInSeconds' => 21600 - $age,
            ];
        }
    }

    // Cancellation target rows do not have a dedicated cancelled_at column.
    // Recover that timestamp from the stored NOTAMC message before deleting
    // anything, so a NOTAM cancelled today is not mistaken for an old record
    // merely because its own last_updated is old.
    $cancelStmt = $pdo->prepare(
        "SELECT notam_text
         FROM notams
         WHERE source = 'FAA_NMS'
           AND environment = :environment
           AND UPPER(COALESCE(notam_type, '')) = 'C'
           AND COALESCE(effective_start, last_updated) IS NOT NULL
           AND COALESCE(effective_start, last_updated) < :cutoff"
    );
    $cancelStmt->execute([
        'environment' => $environment,
        'cutoff' => $cutoff,
    ]);

    $targets = [];
    while ($row = $cancelStmt->fetch()) {
        $text = (string)($row['notam_text'] ?? '');
        if (!preg_match('/\bNOTAMC\s+([A-Z])([0-9]{4})\/([0-9]{2})\b/i', $text, $m)) continue;
        $targets[strtoupper($m[1]) . $m[2] . '/' . $m[3]] = [
            'series' => strtoupper($m[1]),
            'serial' => $m[2],
            'year2' => (int)$m[3],
            'year4' => 2000 + (int)$m[3],
        ];
    }

    $deletedCancelledTargets = 0;
    $deletedExpired = 0;
    $deletedCancellationMessages = 0;

    try {
        $pdo->beginTransaction();

        if ($targets) {
            $deleteTarget = $pdo->prepare(
                "DELETE FROM notams
                 WHERE source = 'FAA_NMS'
                   AND environment = :environment
                   AND series = :series
                   AND (number = :serial OR number = :serial_slash OR number = :target)
                   AND (year = :year4 OR year = :year2 OR year IS NULL)"
            );

            foreach ($targets as $target => $parts) {
                $deleteTarget->execute([
                    'environment' => $environment,
                    'series' => $parts['series'],
                    'serial' => $parts['serial'],
                    'serial_slash' => $parts['serial'] . '/' . str_pad((string)$parts['year2'], 2, '0', STR_PAD_LEFT),
                    'target' => $target,
                    'year4' => $parts['year4'],
                    'year2' => $parts['year2'],
                ]);
                $deletedCancelledTargets += $deleteTarget->rowCount();
            }
        }

        // Normal expired NOTAMs: keep current, future and PERM records. Delete
        // only records whose actual validity end is more than N days behind us.
        do {
            $expiredStmt = $pdo->prepare(
                "DELETE FROM notams
                 WHERE source = 'FAA_NMS'
                   AND environment = :environment
                   AND effective_end IS NOT NULL
                   AND effective_end < :cutoff
                   AND UPPER(COALESCE(effective_end_raw, '')) <> 'PERM'
                 LIMIT 5000"
            );
            $expiredStmt->execute([
                'environment' => $environment,
                'cutoff' => $cutoff,
            ]);
            $batchDeleted = $expiredStmt->rowCount();
            $deletedExpired += $batchDeleted;
        } while ($batchDeleted === 5000);

        // Finally remove old cancellation messages themselves. Their targets
        // were handled above first, preserving the correct three-day history.
        do {
            $cancelDeleteStmt = $pdo->prepare(
                "DELETE FROM notams
                 WHERE source = 'FAA_NMS'
                   AND environment = :environment
                   AND UPPER(COALESCE(notam_type, '')) = 'C'
                   AND COALESCE(effective_start, last_updated) IS NOT NULL
                   AND COALESCE(effective_start, last_updated) < :cutoff
                 LIMIT 5000"
            );
            $cancelDeleteStmt->execute([
                'environment' => $environment,
                'cutoff' => $cutoff,
            ]);
            $batchDeleted = $cancelDeleteStmt->rowCount();
            $deletedCancellationMessages += $batchDeleted;
        } while ($batchDeleted === 5000);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $result = [
        'ok' => true,
        'skipped' => false,
        'retentionDays' => $retentionDays,
        'cutoff' => $cutoff,
        'deletedExpired' => $deletedExpired,
        'deletedCancelledTargets' => $deletedCancelledTargets,
        'deletedCancellationMessages' => $deletedCancellationMessages,
        'deletedTotal' => $deletedExpired + $deletedCancelledTargets + $deletedCancellationMessages,
    ];

    @file_put_contents(
        $markerPath,
        json_encode($result + ['completedAt' => gmdate('Y-m-d\TH:i:s\Z')], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return $result;
}

function nmsStoreSyncError(PDO $pdo, string $environment, string $message): void {
    nmsEnsureSyncState($pdo, $environment);
    $stmt = $pdo->prepare(
        'UPDATE notam_sync_state
         SET last_error = :error
         WHERE source = \'FAA_NMS\' AND environment = :environment'
    );
    $stmt->execute([
        'error' => function_exists('mb_substr')
            ? mb_substr($message, 0, 65535)
            : substr($message, 0, 65535),
        'environment' => $environment,
    ]);
}

function nmsRunDeltaSync(int $bootstrapLookbackSeconds = 600): array {
    $cfg = nmsPrivateConfig();
    $environment = $cfg['env'];
    $pdo = nmsDb();
    $state = nmsSyncState($pdo, $environment);

    $requestStarted = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lastSuccessful = $state['last_successful_sync'] ?? null;

    if ($lastSuccessful) {
        $cursor = new DateTimeImmutable((string)$lastSuccessful, new DateTimeZone('UTC'));
        $age = $requestStarted->getTimestamp() - $cursor->getTimestamp();

        if ($environment === 'production' && $age < 180) {
            return [
                'ok' => false,
                'rateLimitedLocally' => true,
                'environment' => $environment,
                'lastSuccessfulSync' => $lastSuccessful,
                'retryAfterSeconds' => max(1, 180 - $age),
                'error' => 'Production delta sync is limited locally to one pull every 3 minutes.',
            ];
        }

        if ($age > 23 * 3600) {
            $message = 'Delta cursor is older than the safe NMS 24-hour window; a full recovery load is required.';
            nmsStoreSyncError($pdo, $environment, $message);
            return [
                'ok' => false,
                'needsFullLoad' => true,
                'environment' => $environment,
                'lastSuccessfulSync' => $lastSuccessful,
                'error' => $message,
            ];
        }

        // Small overlap prevents a boundary update from being missed. NMS_ID upsert
        // makes replaying the overlap idempotent.
        $since = $cursor->modify('-30 seconds');
    } else {
        $bootstrapLookbackSeconds = max(60, min(3600, $bootstrapLookbackSeconds));
        $since = $requestStarted->modify('-' . $bootstrapLookbackSeconds . ' seconds');
    }

    $sinceIso = $since->format('Y-m-d\TH:i:s\Z');
    $syncThrough = $requestStarted->format('Y-m-d H:i:s');

    $result = nmsGet('/notams', ['lastUpdatedDate' => $sinceIso], 'GEOJSON');
    if (!($result['ok'] ?? false)) {
        $message = (string)($result['error'] ?? 'FAA NMS delta request failed.');
        nmsStoreSyncError($pdo, $environment, $message);
        return [
            'ok' => false,
            'environment' => $environment,
            'since' => $sinceIso,
            'upstreamStatus' => $result['status'] ?? null,
            'error' => $message,
        ];
    }

    $apiResponse = is_array($result['data'] ?? null) ? $result['data'] : [];
    $features = $apiResponse['data']['geojson'] ?? [];
    if (!is_array($features)) $features = [];

    $processed = 0;
    $skipped = 0;
    $cancellationTargets = [];

    try {
        $pdo->beginTransaction();

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                $skipped++;
                continue;
            }

            $record = nmsNormalizeFeature($feature, $environment);
            if ($record === null) {
                $skipped++;
                continue;
            }

            nmsUpsertRecord($pdo, $record);
            $target = nmsApplyCancellationReference($pdo, $record);
            if ($target !== null) $cancellationTargets[] = $target;
            $processed++;
        }

        $requestId = nmsStoreShortText(
            $apiResponse['requestId'] ?? $apiResponse['requestID'] ?? $apiResponse['request_id'] ?? null,
            150
        );

        $stmt = $pdo->prepare(
            'UPDATE notam_sync_state
             SET last_successful_sync = :sync_through,
                 last_request_id = :request_id,
                 last_error = NULL
             WHERE source = \'FAA_NMS\' AND environment = :environment'
        );
        $stmt->execute([
            'sync_through' => $syncThrough,
            'request_id' => $requestId,
            'environment' => $environment,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        nmsStoreSyncError($pdo, $environment, 'Local NOTAM sync failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'environment' => $environment,
            'since' => $sinceIso,
            'error' => 'Local NOTAM sync failed.',
            'detail' => $environment === 'staging' ? $e->getMessage() : null,
        ];
    }

    $retentionCleanup = null;
    try {
        $retentionCleanup = nmsCleanupOldNotams($pdo, $environment, 3, false);
    } catch (Throwable $e) {
        $retentionCleanup = [
            'ok' => false,
            'retentionDays' => 3,
            'error' => $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'environment' => $environment,
        'since' => $sinceIso,
        'syncThrough' => $syncThrough . 'Z',
        'upstreamStatus' => $result['status'] ?? 200,
        'apiStatus' => $apiResponse['status'] ?? null,
        'received' => count($features),
        'processed' => $processed,
        'skipped' => $skipped,
        'cancellationTargets' => array_values(array_unique($cancellationTargets)),
        'retentionCleanup' => $retentionCleanup,
    ];
}
