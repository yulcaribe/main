<?php
declare(strict_types=1);

const YC_RUNTIME_RETENTION_DAYS = 3;
const YC_RUNTIME_MAX_LOG_CONTEXT_BYTES = 12288;

function ycRuntimeRoot(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (is_link($dir)) throw new RuntimeException('Runtime log root must not be a symlink.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Runtime log root could not be created.');
    }
    return $dir;
}

function ycRuntimeDir(string $kind): string {
    if (!in_array($kind, ['nms','health','raw-nms','state'], true)) {
        throw new InvalidArgumentException('Unknown runtime storage kind.');
    }
    $dir = ycRuntimeRoot() . DIRECTORY_SEPARATOR . $kind;
    if (is_link($dir)) throw new RuntimeException('Runtime storage directory must not be a symlink.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Runtime storage directory could not be created.');
    }
    return $dir;
}

function ycRuntimeStatePath(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $name)) throw new InvalidArgumentException('Invalid state filename.');
    return ycRuntimeDir('state') . DIRECTORY_SEPARATOR . $name;
}

function ycRuntimeReadJson(string $path, int $maxBytes = 1048576): ?array {
    if (!is_file($path) || is_link($path)) return null;
    $size = @filesize($path);
    if ($size === false || $size < 1 || $size > $maxBytes) return null;
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $raw = stream_get_contents($fh, $maxBytes + 1);
    } finally {
        fclose($fh);
    }
    if (!is_string($raw) || strlen($raw) > $maxBytes) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function ycRuntimeWriteJson(string $path, array $value): void {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Runtime state could not be encoded.');
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Runtime state directory could not be created.');
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Runtime state could not be written.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Runtime state could not be committed.');
    }
    @chmod($path, 0600);
}

function ycNmsStatePath(string $environment): string {
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $environment);
    return ycRuntimeStatePath('cron_state_' . $safe . '.json');
}

function ycReadNmsState(string $environment): ?array {
    $path = ycNmsStatePath($environment);
    $state = ycRuntimeReadJson($path, 2097152);
    if (is_array($state)) return $state;

    // One-way migration from the old volatile /tmp location. The old file is left untouched.
    $legacy = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'yulcaribe_nms'
        . DIRECTORY_SEPARATOR . 'cron_state_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $environment) . '.json';
    $state = ycRuntimeReadJson($legacy, 2097152);
    if (is_array($state)) {
        try { ycRuntimeWriteJson($path, $state); } catch (Throwable) {}
        return $state;
    }
    return null;
}

function ycRuntimeCleanup(int $days = YC_RUNTIME_RETENTION_DAYS): void {
    $days = max(1, min(30, $days));
    $cutoff = time() - ($days * 86400);
    $patterns = [
        'nms' => '/^nms-\d{4}-\d{2}-\d{2}\.log$/D',
        'health' => '/^health-\d{4}-\d{2}-\d{2}\.log$/D',
        'raw-nms' => '/^raw-nms-\d{4}-\d{2}-\d{2}\.jsonl(?:\.gz)?$/D',
    ];
    foreach ($patterns as $kind => $pattern) {
        $dir = ycRuntimeDir($kind);
        try {
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile()) continue;
                if (!preg_match($pattern, $file->getFilename())) continue;
                $mtime = $file->getMTime();
                if ($mtime < $cutoff) @unlink($file->getPathname());
            }
        } catch (Throwable) {
            // Cleanup failure must never take the application down.
        }
    }
}

function ycRuntimeLog(string $kind, string $level, string $message, array $context = []): void {
    if (!in_array($kind, ['nms','health'], true)) throw new InvalidArgumentException('Invalid log kind.');
    static $cleaned = false;
    if (!$cleaned) {
        ycRuntimeCleanup(YC_RUNTIME_RETENTION_DAYS);
        $cleaned = true;
    }
    $level = strtoupper(trim($level));
    if (!in_array($level, ['DEBUG','INFO','WARNING','ERROR','RECOVERED'], true)) $level = 'INFO';
    $message = trim($message);
    if (strlen($message) > 2000) $message = substr($message, 0, 2000) . '…';
    $suffix = '';
    if ($context) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded)) {
            if (strlen($encoded) <= YC_RUNTIME_MAX_LOG_CONTEXT_BYTES) $suffix = ' ' . $encoded;
            else $suffix = ' ' . json_encode(['contextTruncated'=>true,'bytes'=>strlen($encoded)], JSON_UNESCAPED_SLASHES);
        }
    }
    $line = '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $level . ' ' . $message . $suffix . PHP_EOL;
    $path = ycRuntimeDir($kind) . DIRECTORY_SEPARATOR . $kind . '-' . gmdate('Y-m-d') . '.log';
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    @chmod($path, 0600);
}

function ycArchiveRawNmsJson(string $json): bool {
    $json = trim($json);
    if ($json === '') return false;
    // Ensure the archive stays JSONL even if a caller accidentally passes pretty-printed JSON.
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $compact = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($compact)) $json = $compact;
    }
    static $handle = null;
    static $handleDate = null;
    static $plainHandle = null;
    static $plainDate = null;
    $date = gmdate('Y-m-d');

    if (function_exists('gzopen')) {
        if ($handle === null || $handleDate !== $date) {
            if (is_resource($handle)) @gzclose($handle);
            $path = ycRuntimeDir('raw-nms') . DIRECTORY_SEPARATOR . 'raw-nms-' . $date . '.jsonl.gz';
            $handle = @gzopen($path, 'ab6');
            $handleDate = $date;
            if ($handle !== false) @chmod($path, 0600);
        }
        if ($handle !== false && $handle !== null) return @gzwrite($handle, $json . "\n") !== false;
    }

    if ($plainHandle === null || $plainDate !== $date) {
        if (is_resource($plainHandle)) @fclose($plainHandle);
        $path = ycRuntimeDir('raw-nms') . DIRECTORY_SEPARATOR . 'raw-nms-' . $date . '.jsonl';
        $plainHandle = @fopen($path, 'ab');
        $plainDate = $date;
        if (is_resource($plainHandle)) @chmod($path, 0600);
    }
    if (!is_resource($plainHandle)) return false;
    $written = @fwrite($plainHandle, $json . "\n");
    @fflush($plainHandle);
    return $written !== false;
}

function ycArchiveRawNmsValue(mixed $value): bool {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) && ycArchiveRawNmsJson($json);
}

function ycRuntimeLogFiles(): array {
    $result = [];
    $patterns = [
        'nms' => '/^nms-(\d{4}-\d{2}-\d{2})\.log$/D',
        'health' => '/^health-(\d{4}-\d{2}-\d{2})\.log$/D',
        'raw-nms' => '/^raw-nms-(\d{4}-\d{2}-\d{2})\.jsonl(?:\.gz)?$/D',
    ];
    foreach ($patterns as $kind => $pattern) {
        $dir = ycRuntimeDir($kind);
        try {
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile()) continue;
                if (!preg_match($pattern, $file->getFilename(), $m)) continue;
                $result[] = [
                    'type'=>$kind,
                    'date'=>$m[1],
                    'file'=>$file->getFilename(),
                    'bytes'=>$file->getSize(),
                    'modifiedAt'=>gmdate('c', $file->getMTime()),
                ];
            }
        } catch (Throwable) {}
    }
    usort($result, static function(array $a, array $b): int {
        $date = strcmp((string)$b['date'], (string)$a['date']);
        return $date !== 0 ? $date : strcmp((string)$a['type'], (string)$b['type']);
    });
    return $result;
}

function ycRuntimeResolveDownload(string $type, string $file): ?string {
    $patterns = [
        'nms' => '/^nms-\d{4}-\d{2}-\d{2}\.log$/D',
        'health' => '/^health-\d{4}-\d{2}-\d{2}\.log$/D',
        'raw-nms' => '/^raw-nms-\d{4}-\d{2}-\d{2}\.jsonl(?:\.gz)?$/D',
    ];
    if (!isset($patterns[$type]) || !preg_match($patterns[$type], $file) || basename($file) !== $file) return null;
    $dir = ycRuntimeDir($type);
    $candidate = $dir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($candidate) || is_link($candidate)) return null;
    $realDir = realpath($dir);
    $realFile = realpath($candidate);
    if ($realDir === false || $realFile === false || !str_starts_with($realFile, $realDir . DIRECTORY_SEPARATOR)) return null;
    return $realFile;
}
