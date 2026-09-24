<?php
declare(strict_types=1);

function nmsFullProgressPath(string $environment): string {
    return nmsCacheDir() . DIRECTORY_SEPARATOR . 'initial_progress_' . $environment . '.json';
}

function nmsFullReadProgress(string $environment): ?array {
    $path = nmsFullProgressPath($environment);
    if (!is_file($path)) return null;

    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (empty($data['xmlPath']) || !is_file((string)$data['xmlPath'])) return null;
    return $data;
}

function nmsFullWriteProgress(string $environment, array $state): void {
    $path = nmsFullProgressPath($environment);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Initial load progress could not be saved.');
    }
}

function nmsFullExpectedFromXml(string $xmlPath): ?int {
    $fh = @fopen($xmlPath, 'rb');
    if (!$fh) return null;
    $head = fread($fh, 262144);
    fclose($fh);
    if (!is_string($head)) return null;
    if (preg_match('/numberReturned=["\'](\d+)["\']/', $head, $m)) return (int)$m[1];
    return null;
}

function nmsFullFindReusableXml(string $environment): ?string {
    $pattern = nmsCacheDir() . DIRECTORY_SEPARATOR . 'initial_' . $environment . '_*.bin.xml';
    $files = glob($pattern) ?: [];
    usort($files, fn(string $a, string $b): int => (int)@filemtime($b) <=> (int)@filemtime($a));

    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && (time() - $mtime) <= 21600 && is_file($file) && filesize($file) > 0) {
            return $file;
        }
    }
    return null;
}

function nmsFullPrepareState(string $environment): array {
    $existing = nmsFullReadProgress($environment);
    if ($existing !== null) return $existing;

    $xmlPath = nmsFullFindReusableXml($environment);
    $downloadPath = null;
    $source = 'reused-timeout-snapshot';

    if ($xmlPath === null) {
        $source = 'faa-initial-load';
        $meta = nmsGet('/notams/il', ['allowRedirect' => 'false'], null);
        if (!($meta['ok'] ?? false)) {
            throw new RuntimeException((string)($meta['error'] ?? 'FAA NMS initial load request failed.'));
        }

        $base = nmsCacheDir() . DIRECTORY_SEPARATOR . 'initial_' . $environment . '_' . getmypid() . '_' . time();
        $downloadPath = $base . '.bin';

        $metaData = is_array($meta['data'] ?? null) ? $meta['data'] : [];
        $contentUrl = $metaData['data']['url'] ?? null;

        if (is_string($contentUrl) && trim($contentUrl) !== '') {
            $download = nmsDownloadToFile(nmsFullContentApiPath($contentUrl), $downloadPath);
            if (!($download['ok'] ?? false)) {
                throw new RuntimeException((string)($download['error'] ?? 'FAA NMS initial load content download failed.'));
            }
        } else {
            $payload = $meta['body'] ?? null;
            if (!is_string($payload) || $payload === '') {
                throw new RuntimeException('FAA NMS initial load payload was empty.');
            }
            nmsFullWritePayload($payload, $downloadPath);
            unset($payload);
        }

        $xmlPath = nmsFullMaterializeXml($downloadPath);
    }

    $state = [
        'environment' => $environment,
        'xmlPath' => $xmlPath,
        'downloadPath' => $downloadPath,
        'byteOffset' => 0,
        'processed' => 0,
        'skipped' => 0,
        'expected' => nmsFullExpectedFromXml($xmlPath),
        'startedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'source' => $source,
    ];
    nmsFullWriteProgress($environment, $state);
    return $state;
}

function nmsFullNextMessage($fh, int $offset): ?array {
    if (fseek($fh, $offset) !== 0) {
        throw new RuntimeException('Initial load cursor could not seek to the saved position.');
    }

    $buffer = '';
    $baseOffset = $offset;
    $closeTag = null;
    $foundStart = false;

    while (!feof($fh)) {
        $chunk = fread($fh, 262144);
        if ($chunk === false) throw new RuntimeException('Initial load snapshot read failed.');
        if ($chunk === '') break;
        $buffer .= $chunk;

        if (!$foundStart) {
            if (preg_match('/<(?:(?<p>[A-Za-z_][A-Za-z0-9_.-]*):)?AIXMBasicMessage\b/', $buffer, $m, PREG_OFFSET_CAPTURE)) {
                $start = (int)$m[0][1];
                $prefix = isset($m['p'][0]) && is_string($m['p'][0]) ? $m['p'][0] : '';
                $baseOffset += $start;
                $buffer = substr($buffer, $start);
                $closeTag = '</' . ($prefix !== '' ? $prefix . ':' : '') . 'AIXMBasicMessage>';
                $foundStart = true;
            } elseif (strlen($buffer) > 512) {
                $drop = strlen($buffer) - 512;
                $baseOffset += $drop;
                $buffer = substr($buffer, -512);
                continue;
            }
        }

        if ($foundStart && $closeTag !== null) {
            $endPos = strpos($buffer, $closeTag);
            if ($endPos !== false) {
                $end = $endPos + strlen($closeTag);
                return [
                    'xml' => substr($buffer, 0, $end),
                    'nextOffset' => $baseOffset + $end,
                ];
            }
        }

        if (strlen($buffer) > 16777216) {
            throw new RuntimeException('One initial load record exceeded the safe parser buffer.');
        }
    }

    return null;
}

function nmsFullCleanupState(string $environment, array $state): void {
    @unlink(nmsFullProgressPath($environment));
    $xmlPath = (string)($state['xmlPath'] ?? '');
    $downloadPath = (string)($state['downloadPath'] ?? '');
    if ($xmlPath !== '' && is_file($xmlPath)) @unlink($xmlPath);
    if ($downloadPath !== '' && is_file($downloadPath)) @unlink($downloadPath);
}

function nmsRunFullLoadSlice(int $limit = 250, int $maxSeconds = 7): array {
    $cfg = nmsPrivateConfig();
    $environment = $cfg['env'];
    $pdo = nmsDb();
    $sync = nmsSyncState($pdo, $environment);

    $activeProgress = nmsFullReadProgress($environment);
    if ($activeProgress === null && !empty($sync['last_full_load'])) {
        $last = strtotime((string)$sync['last_full_load'] . ' UTC');
        if ($last !== false && (time() - $last) < 86400) {
            return [
                'ok' => false,
                'complete' => true,
                'rateLimitedLocally' => true,
                'environment' => $environment,
                'error' => 'Full load was already completed within the last 24 hours.',
                'lastFullLoad' => $sync['last_full_load'],
            ];
        }
    }

    $limit = max(25, min(500, $limit));
    $maxSeconds = max(2, min(10, $maxSeconds));
    $startedSlice = microtime(true);

    try {
        $state = $activeProgress ?? nmsFullPrepareState($environment);
        $fh = @fopen((string)$state['xmlPath'], 'rb');
        if (!$fh) throw new RuntimeException('Initial load snapshot could not be opened.');

        $offset = (int)($state['byteOffset'] ?? 0);
        $sliceProcessed = 0;
        $sliceSkipped = 0;
        $reachedEof = false;

        $pdo->beginTransaction();

        while (($sliceProcessed + $sliceSkipped) < $limit) {
            if ((microtime(true) - $startedSlice) >= $maxSeconds) break;

            $next = nmsFullNextMessage($fh, $offset);
            if ($next === null) {
                $reachedEof = true;
                break;
            }

            $offset = (int)$next['nextOffset'];
            $record = nmsFullNormalizeMessage((string)$next['xml'], $environment);
            if ($record === null) {
                $sliceSkipped++;
                continue;
            }

            nmsFullUpsert($pdo, $record);
            $sliceProcessed++;
        }

        if ($pdo->inTransaction()) $pdo->commit();
        fclose($fh);

        $clearError = $pdo->prepare(
            "UPDATE notam_sync_state
             SET last_error = NULL
             WHERE source = 'FAA_NMS' AND environment = :environment"
        );
        $clearError->execute(['environment' => $environment]);

        $state['byteOffset'] = $offset;
        $state['processed'] = (int)($state['processed'] ?? 0) + $sliceProcessed;
        $state['skipped'] = (int)($state['skipped'] ?? 0) + $sliceSkipped;
        $state['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

        if (!$reachedEof) {
            nmsFullWriteProgress($environment, $state);
            $expected = isset($state['expected']) ? (int)$state['expected'] : null;
            $done = (int)$state['processed'] + (int)$state['skipped'];

            return [
                'ok' => true,
                'complete' => false,
                'environment' => $environment,
                'processed' => (int)$state['processed'],
                'skipped' => (int)$state['skipped'],
                'sliceProcessed' => $sliceProcessed,
                'sliceSkipped' => $sliceSkipped,
                'expected' => $expected,
                'progressPercent' => $expected && $expected > 0 ? min(99.9, round(($done / $expected) * 100, 1)) : null,
                'source' => $state['source'] ?? null,
            ];
        }

        $completedAt = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "UPDATE notam_sync_state
             SET last_full_load = :completed,
                 last_error = NULL
             WHERE source = 'FAA_NMS' AND environment = :environment"
        );
        $stmt->execute([
            'completed' => $completedAt,
            'environment' => $environment,
        ]);

        $finalProcessed = (int)$state['processed'];
        $finalSkipped = (int)$state['skipped'];
        $expected = isset($state['expected']) ? (int)$state['expected'] : null;
        nmsFullCleanupState($environment, $state);

        $catchup = nmsRunDeltaSync();

        return [
            'ok' => true,
            'complete' => true,
            'environment' => $environment,
            'processed' => $finalProcessed,
            'skipped' => $finalSkipped,
            'expected' => $expected,
            'progressPercent' => 100,
            'completedAt' => $completedAt . 'Z',
            'catchup' => $catchup,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try {
            nmsStoreSyncError($pdo, $environment, 'Full load slice failed: ' . $e->getMessage());
        } catch (Throwable) {
        }

        return [
            'ok' => false,
            'complete' => false,
            'environment' => $environment,
            'error' => 'FAA NMS full load slice failed.',
            'detail' => $environment === 'staging' ? $e->getMessage() : null,
        ];
    }
}
