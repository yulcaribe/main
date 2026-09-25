<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/internal/client.php';
require_once __DIR__ . '/internal/store.php';
require_once __DIR__ . '/internal/full_payload.php';
require_once __DIR__ . '/internal/full_store.php';
require_once __DIR__ . '/internal/full_parser.php';
require_once __DIR__ . '/internal/full_run.php';

function nmsCronStatePath(string $environment): string {
    return nmsCacheDir() . DIRECTORY_SEPARATOR . 'cron_state_' . $environment . '.json';
}

function nmsCronLogDir(): string {
    $dir = dirname(__DIR__, 4) . '/logs/main/notam';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function nmsCronCleanupLogs(int $days = 3): void {
    $cutoff = time() - max(1, $days) * 86400;
    foreach ((array)glob(nmsCronLogDir() . DIRECTORY_SEPARATOR . 'nms-*.log') as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) @unlink($file);
    }
}

function nmsCronCleanupLegacyLog(int $days = 3): void {
    $path = dirname(__DIR__, 4) . '/nms_cron.log';
    if (!is_file($path)) return;

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || !$lines) return;

    $cutoff = time() - max(1, $days) * 86400;
    $keep = [];
    $unparsed = [];

    foreach ($lines as $line) {
        if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $ts = strtotime((string)$m[1]);
            if ($ts !== false && $ts >= $cutoff) $keep[] = $line;
        } else {
            $unparsed[] = $line;
        }
    }

    if ($unparsed) {
        $keep = array_merge($keep, array_slice($unparsed, -200));
    }

    @file_put_contents(
        $path,
        $keep ? implode(PHP_EOL, $keep) . PHP_EOL : '',
        LOCK_EX
    );
}

function nmsCronLog(string $message): void {
    static $cleaned = false;
    if (!$cleaned) {
        nmsCronCleanupLogs(3);
        nmsCronCleanupLegacyLog(3);
        $cleaned = true;
    }

    $line = '[' . gmdate('Y-m-d\\TH:i:s\\Z') . '] ' . $message . PHP_EOL;
    @file_put_contents(
        nmsCronLogDir() . DIRECTORY_SEPARATOR . 'nms-' . gmdate('Y-m-d') . '.log',
        $line,
        FILE_APPEND | LOCK_EX
    );
    fwrite(STDOUT, $line);
    fflush(STDOUT);
}

function nmsCronWriteState(string $environment, array $state): void {
    $state['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
    @file_put_contents(
        nmsCronStatePath($environment),
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function nmsCronFullLoad(string $environment, int $maxRuntimeSeconds = 900): array {
    $started = microtime(true);
    $rounds = 0;
    $last = null;

    while ((microtime(true) - $started) < $maxRuntimeSeconds) {
        $rounds++;
        $last = nmsRunFullLoadSlice(500, 10);

        if (!($last['ok'] ?? false)) {
            return ['rounds' => $rounds] + $last;
        }

        nmsCronWriteState($environment, [
            'ok' => true,
            'mode' => 'initial-load',
            'running' => !($last['complete'] ?? false),
            'complete' => (bool)($last['complete'] ?? false),
            'processed' => (int)($last['processed'] ?? 0),
            'skipped' => (int)($last['skipped'] ?? 0),
            'expected' => $last['expected'] ?? null,
            'progressPercent' => $last['progressPercent'] ?? null,
            'rounds' => $rounds,
        ]);

        if ($last['complete'] ?? false) {
            return ['rounds' => $rounds] + $last;
        }

        usleep(100000);
    }

    return [
        'ok' => true,
        'complete' => false,
        'pausedForNextCron' => true,
        'environment' => $environment,
        'rounds' => $rounds,
        'processed' => (int)($last['processed'] ?? 0),
        'skipped' => (int)($last['skipped'] ?? 0),
        'expected' => $last['expected'] ?? null,
        'progressPercent' => $last['progressPercent'] ?? null,
    ];
}

$cfg = nmsPrivateConfig();
$environment = $cfg['env'];

$lockPath = nmsCacheDir() . DIRECTORY_SEPARATOR . 'cron_' . $environment . '.lock';
$lock = @fopen($lockPath, 'c+');
if (!$lock) {
    nmsCronLog("ERROR: NMS cron lock could not be opened.");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit(0);
}

try {
    if ($environment !== 'production') {
        $result = [
            'ok' => false,
            'mode' => 'blocked',
            'environment' => $environment,
            'error' => 'Automatic NMS cron is enabled only for production.',
        ];
        nmsCronWriteState($environment, $result);
        nmsCronLog('ERROR: ' . json_encode($result, JSON_UNESCAPED_SLASHES));
        exit(2);
    }

    $pdo = nmsDb();
    $state = nmsSyncState($pdo, $environment);
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notams WHERE source = 'FAA_NMS' AND environment = :environment"
    );
    $countStmt->execute(['environment' => $environment]);
    $notamCount = (int)$countStmt->fetchColumn();

    if ($notamCount === 0 || empty($state['last_full_load'])) {
        $existingProgress = nmsFullReadProgress($environment);
        $resuming = is_array($existingProgress);
        nmsCronWriteState($environment, [
            'ok' => true,
            'mode' => $resuming ? 'initial-load' : 'initial-download',
            'running' => true,
            'complete' => false,
            'processed' => (int)($existingProgress['processed'] ?? 0),
            'skipped' => (int)($existingProgress['skipped'] ?? 0),
            'expected' => $existingProgress['expected'] ?? null,
            'progressPercent' => null,
            'message' => $resuming
                ? 'Existing FAA Initial Load snapshot is being resumed from saved progress.'
                : 'FAA Initial Load snapshot is being downloaded/prepared.',
        ]);
        nmsCronLog('Production baseline missing; Initial Load starting.');
        if ($resuming) {
            nmsCronLog(
                'Resuming existing Initial Load snapshot from saved progress'
                . ' · processed=' . (int)($existingProgress['processed'] ?? 0)
                . ' · byteOffset=' . (int)($existingProgress['byteOffset'] ?? 0)
                . '.'
            );
        } else {
            nmsCronLog('Downloading/preparing FAA Initial Load snapshot. No DB progress is expected until this step finishes.');
        }
        $result = nmsCronFullLoad($environment);
        nmsCronWriteState($environment, [
            'ok' => (bool)($result['ok'] ?? false),
            'mode' => 'initial-load',
            'running' => !($result['complete'] ?? false),
            'complete' => (bool)($result['complete'] ?? false),
            'processed' => (int)($result['processed'] ?? 0),
            'skipped' => (int)($result['skipped'] ?? 0),
            'expected' => $result['expected'] ?? null,
            'progressPercent' => $result['progressPercent'] ?? null,
            'pausedForNextCron' => (bool)($result['pausedForNextCron'] ?? false),
            'error' => $result['detail'] ?? $result['error'] ?? null,
        ]);
    } else {
        nmsCronLog('Baseline present; Delta Sync starting.');
        $result = nmsRunDeltaSync();

        if (($result['needsFullLoad'] ?? false) === true) {
            $result = nmsCronFullLoad($environment);
            $mode = 'recovery-full-load';
        } else {
            $mode = 'delta';
        }

        nmsCronWriteState($environment, [
            'ok' => (bool)($result['ok'] ?? false),
            'mode' => $mode,
            'running' => false,
            'processed' => (int)($result['processed'] ?? 0),
            'received' => isset($result['received']) ? (int)$result['received'] : null,
            'skipped' => (int)($result['skipped'] ?? 0),
            'syncThrough' => $result['syncThrough'] ?? null,
            'retryAfterSeconds' => $result['retryAfterSeconds'] ?? null,
            'error' => $result['error'] ?? $result['detail'] ?? null,
        ]);
    }

    if (($result['ok'] ?? false) === true) {
        $result['retentionCleanup'] = nmsCleanupOldNotams($pdo, $environment, 3, false);
    }

    nmsCronLog('Result: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    $result = [
        'ok' => false,
        'mode' => 'exception',
        'environment' => $environment,
        'error' => $e->getMessage(),
    ];
    nmsCronWriteState($environment, $result);
    nmsCronLog('ERROR: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
