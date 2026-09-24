<?php
declare(strict_types=1);

function nmsRunFullLoad(): array {
    if (!class_exists(XMLReader::class)) {
        return ['ok' => false, 'error' => 'PHP XMLReader extension is not enabled.'];
    }

    $cfg = nmsPrivateConfig();
    $environment = $cfg['env'];
    $pdo = nmsDb();
    $state = nmsSyncState($pdo, $environment);

    if (!empty($state['last_full_load'])) {
        $last = strtotime((string)$state['last_full_load'] . ' UTC');
        if ($last !== false && (time() - $last) < 86400) {
            return [
                'ok' => false,
                'environment' => $environment,
                'rateLimitedLocally' => true,
                'error' => 'Full load was already completed within the last 24 hours.',
                'lastFullLoad' => $state['last_full_load'],
            ];
        }
    }

    $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $tempBase = nmsCacheDir() . DIRECTORY_SEPARATOR . 'initial_' . $environment . '_' . getmypid() . '_' . time();
    $downloadPath = $tempBase . '.bin';
    $xmlPath = null;

    try {
        $meta = nmsGet('/notams/il', ['allowRedirect' => 'false'], null);
        if (!($meta['ok'] ?? false)) {
            throw new RuntimeException((string)($meta['error'] ?? 'FAA NMS initial load request failed.'));
        }

        $metaData = is_array($meta['data'] ?? null) ? $meta['data'] : [];
        $contentUrl = $metaData['data']['url'] ?? null;

        $payload = null;
        if (is_string($contentUrl) && trim($contentUrl) !== '') {
            $content = nmsGet(nmsFullContentApiPath($contentUrl), [], null);
            if (!($content['ok'] ?? false)) {
                throw new RuntimeException((string)($content['error'] ?? 'FAA NMS initial load content download failed.'));
            }
            $payload = $content['body'] ?? null;
        } else {
            $payload = $meta['body'] ?? null;
        }

        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('FAA NMS initial load payload was empty.');
        }

        nmsFullWritePayload($payload, $downloadPath);
        unset($payload);
        $xmlPath = nmsFullMaterializeXml($downloadPath);

        $reader = new XMLReader();
        if (!$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('FAA NMS initial load XML could not be opened.');
        }

        $expected = null;
        $processed = 0;
        $skipped = 0;
        $batch = 0;
        $pdo->beginTransaction();

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) continue;

            if ($reader->localName === 'FeatureCollection' && $expected === null) {
                $nr = $reader->getAttribute('numberReturned');
                if ($nr !== null && is_numeric($nr)) $expected = (int)$nr;
                continue;
            }

            if ($reader->localName !== 'AIXMBasicMessage') continue;
            $outer = $reader->readOuterXml();
            if (!is_string($outer) || $outer === '') {
                $skipped++;
                continue;
            }

            $record = nmsFullNormalizeMessage($outer, $environment);
            if ($record === null) {
                $skipped++;
                continue;
            }

            nmsFullUpsert($pdo, $record);
            $processed++;
            $batch++;

            if ($batch >= 250) {
                $pdo->commit();
                $pdo->beginTransaction();
                $batch = 0;
            }
        }

        $reader->close();
        if ($pdo->inTransaction()) $pdo->commit();

        $startedSql = $started->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "UPDATE notam_sync_state
             SET last_full_load = :started,
                 last_successful_sync = CASE
                    WHEN last_successful_sync IS NULL OR last_successful_sync < :started2 THEN :started3
                    ELSE last_successful_sync
                 END,
                 last_error = NULL
             WHERE source = 'FAA_NMS' AND environment = :environment"
        );
        $stmt->execute([
            'started' => $startedSql,
            'started2' => $startedSql,
            'started3' => $startedSql,
            'environment' => $environment,
        ]);

        $catchup = nmsRunDeltaSync();

        return [
            'ok' => true,
            'environment' => $environment,
            'expected' => $expected,
            'processed' => $processed,
            'skipped' => $skipped,
            'downloadedBytes' => is_file($downloadPath) ? (int)filesize($downloadPath) : null,
            'startedAt' => $started->format('Y-m-d\TH:i:s\Z'),
            'catchup' => $catchup,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        nmsStoreSyncError($pdo, $environment, 'Full load failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'environment' => $environment,
            'error' => 'FAA NMS full load failed.',
            'detail' => $environment === 'staging' ? $e->getMessage() : null,
        ];
    } finally {
        if (is_string($xmlPath) && is_file($xmlPath)) @unlink($xmlPath);
        if (is_file($downloadPath)) @unlink($downloadPath);
    }
}
