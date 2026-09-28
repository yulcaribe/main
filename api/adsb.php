<?php
declare(strict_types=1);

function ycAdsbSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass']);
}

function ycAdsbRequireSameOrigin(): void {
    header('Vary: Origin, Sec-Fetch-Site, Referer');
    header('Cross-Origin-Resource-Policy: same-origin');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (($fetchSite !== '' && $fetchSite !== 'same-origin')
        || ($origin !== ''
            ? !in_array($origin, ['https://yulcaribe.com','https://yulcaribe.com:443'], true)
            : !ycAdsbSameOrigin($referer))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok'=>false,'error'=>'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

function ycAdsbJson(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ycAdsbParseBox(string $raw): array {
    $raw = trim($raw);
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', $raw)) {
        throw new InvalidArgumentException('Geçersiz ADS-B box.');
    }
    [$south,$north,$west,$east] = array_map('floatval', explode(',', $raw));
    if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east) {
        throw new InvalidArgumentException('ADS-B box sınır dışında.');
    }
    if (($north - $south) > 60 || ($east - $west) > 120) {
        throw new InvalidArgumentException('ADS-B görünümü çok geniş.');
    }
    return [$south,$north,$west,$east];
}

function ycAdsbCurl(string $url, array $headers = [], ?string $referer = null): CurlHandle {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 7,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'YulCaribe/1.0 ADS-B',
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($referer !== null) curl_setopt($ch, CURLOPT_REFERER, $referer);
    return $ch;
}

function ycAdsbRunProviders(array $providers, callable $onComplete): void {
    if (!function_exists('curl_multi_init')) throw new RuntimeException('PHP cURL multi aktif değil.');
    $multi = curl_multi_init();
    $handles = [];
    foreach ($providers as $name => $provider) {
        $ch = ycAdsbCurl($provider['url'], $provider['headers'] ?? [], $provider['referer'] ?? null);
        $id = spl_object_id($ch);
        $handles[$id] = ['name'=>$name,'handle'=>$ch,'parse'=>$provider['parse']];
        curl_multi_add_handle($multi, $ch);
    }

    $running = null;
    do {
        do {
            $mrc = curl_multi_exec($multi, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $meta = $handles[$id] ?? null;
            if ($meta === null) continue;

            $body = curl_multi_getcontent($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $rows = [];

            if (is_string($body) && $http === 200 && $error === '') {
                $payload = json_decode($body, true);
                if (is_array($payload)) {
                    try {
                        $parse = $meta['parse'];
                        $rows = $parse($payload);
                    } catch (Throwable $e) {
                        error_log('[adsb '.$meta['name'].' parse] '.$e->getMessage());
                    }
                }
            } elseif ($error !== '') {
                error_log('[adsb '.$meta['name'].'] '.$error);
            }

            $onComplete($meta['name'], $http, $rows);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($handles[$id]);
        }

        if ($running > 0) {
            $selected = curl_multi_select($multi, 0.5);
            if ($selected === -1) usleep(10000);
        }
    } while ($running > 0 || $handles !== []);

    curl_multi_close($multi);
}

function ycAdsbSeenPos(array $row): float {
    return is_numeric($row['seenPos'] ?? null) ? max(0.0, (float)$row['seenPos']) : INF;
}

function ycAdsbMergeRows(array &$best, array $rows, ?string $onlyIcao = null): array {
    $changed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $icao = strtolower((string)($row['icao'] ?? ''));
        if (!preg_match('/^[0-9a-f]{6}$/', $icao)) continue;
        if ($onlyIcao !== null && $icao !== $onlyIcao) continue;
        if (!isset($best[$icao]) || ycAdsbSeenPos($row) < ycAdsbSeenPos($best[$icao])) {
            $best[$icao] = $row;
            $changed[] = $row;
        }
    }
    return $changed;
}

function ycAdsbStreamLine(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    if (function_exists('ob_flush')) @ob_flush();
    flush();
}

ycAdsbRequireSameOrigin();

require_once __DIR__ . '/adsbtat.php';
require_once __DIR__ . '/adsblol.php';
require_once __DIR__ . '/adsbfi.php';

$icao = strtolower(trim((string)($_GET['icao'] ?? '')));
$boxRaw = trim((string)($_GET['box'] ?? ''));
$stream = ($_GET['stream'] ?? '') === '1';

if ($icao !== '' && $boxRaw !== '') {
    ycAdsbJson(400, ['ok'=>false,'error'=>'icao ve box aynı anda kullanılamaz.']);
}

try {
    if ($icao !== '') {
        if (!preg_match('/^[0-9a-f]{6}$/', $icao)) throw new InvalidArgumentException('Geçerli 6 haneli ICAO HEX gerekli.');
        $providers = [
            'tat' => ['url'=>ycAdsbTatIcaoEndpoint($icao), 'parse'=>'ycAdsbTatParseList', 'referer'=>'https://globe.theairtraffic.com/', 'headers'=>['Cache-Control: no-cache','Pragma: no-cache','X-Requested-With: XMLHttpRequest']],
            'adsblol' => ['url'=>ycAdsbLolIcaoEndpoint($icao), 'parse'=>'ycAdsbLolParseList'],
            'adsbfi' => ['url'=>ycAdsbFiIcaoEndpoint($icao), 'parse'=>'ycAdsbFiParseList'],
        ];
        $best = [];
        $sourceStatus = [];
        $available = [];
        ycAdsbRunProviders($providers, static function(string $name, int $http, array $rows) use (&$best, &$sourceStatus, &$available, $icao): void {
            $sourceStatus[$name] = $http;
            $changed = ycAdsbMergeRows($best, $rows, $icao);
            if ($changed !== []) $available[] = $name;
        });
        if (!isset($best[$icao])) {
            ycAdsbJson(404, [
                'ok'=>false,
                'icao'=>$icao,
                'aircraft'=>null,
                'availableSources'=>array_values(array_unique($available)),
                'sourceStatus'=>$sourceStatus,
                'error'=>'Uçak bulunamadı.',
            ]);
        }
        ycAdsbJson(200, [
            'ok'=>true,
            'icao'=>$icao,
            'positionSource'=>$best[$icao]['provider'],
            'availableSources'=>array_values(array_unique($available)),
            'sourceStatus'=>$sourceStatus,
            'aircraft'=>$best[$icao],
        ]);
    }

    if ($boxRaw === '') throw new InvalidArgumentException('icao veya box gerekli.');
    $box = ycAdsbParseBox($boxRaw);
    $providers = [
        'tat' => ['url'=>ycAdsbTatAreaEndpoint($box), 'parse'=>'ycAdsbTatParseList', 'referer'=>'https://globe.theairtraffic.com/', 'headers'=>['Cache-Control: no-cache','Pragma: no-cache','X-Requested-With: XMLHttpRequest']],
        'adsblol' => ['url'=>ycAdsbLolAreaEndpoint($box), 'parse'=>'ycAdsbLolParseList'],
        'adsbfi' => ['url'=>ycAdsbFiAreaEndpoint($box), 'parse'=>'ycAdsbFiParseList'],
    ];

    if ($stream) {
        http_response_code(200);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        ob_implicit_flush(true);

        $best = [];
        $sourceStatus = [];
        ycAdsbRunProviders($providers, static function(string $name, int $http, array $rows) use (&$best, &$sourceStatus): void {
            $sourceStatus[$name] = $http;
            $changed = ycAdsbMergeRows($best, $rows);
            ycAdsbStreamLine([
                'type'=>'batch',
                'source'=>$name,
                'status'=>$http,
                'at'=>round(microtime(true) * 1000),
                'aircraft'=>$changed,
            ]);
        });
        ycAdsbStreamLine([
            'type'=>'end',
            'at'=>round(microtime(true) * 1000),
            'count'=>count($best),
            'sourceStatus'=>$sourceStatus,
        ]);
        exit;
    }

    $best = [];
    $sourceStatus = [];
    ycAdsbRunProviders($providers, static function(string $name, int $http, array $rows) use (&$best, &$sourceStatus): void {
        $sourceStatus[$name] = $http;
        ycAdsbMergeRows($best, $rows);
    });
    ycAdsbJson(200, [
        'ok'=>true,
        'box'=>$box,
        'count'=>count($best),
        'sourceStatus'=>$sourceStatus,
        'aircraft'=>array_values($best),
    ]);
} catch (InvalidArgumentException $e) {
    ycAdsbJson(400, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('[adsb] '.$e->getMessage());
    ycAdsbJson(503, ['ok'=>false,'error'=>'ADS-B motoru kullanılamıyor.']);
}
