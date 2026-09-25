<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/auth.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/client.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/store.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/health.php';
require_once dirname(__DIR__, 2) . '/weather/core.php';

ycApiV1Headers('no-store, max-age=0');
if (!nmsHealthAuthenticated()) {
    ycApiV1Respond(403, ['ok'=>false,'error'=>'Health authentication required.']);
}

function healthConfigPath(): string {
    return dirname(__DIR__, 4) . '/data.php';
}

function healthLoadConfig(): array {
    $path = healthConfigPath();
    if (!is_file($path)) throw new RuntimeException('data.php bulunamadı.');
    $cfg = require $path;
    if (!is_array($cfg)) throw new RuntimeException('data.php geçersiz.');
    return $cfg;
}

function healthHttp(string $url, bool $head = false): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'ms'=>null,'error'=>'cURL yok'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_USERAGENT=>'YulCaribe-Health/1.0',
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_NOBODY=>$head,
    ]);
    $started = microtime(true);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $ms = round((microtime(true) - $started) * 1000, 1);
    curl_close($ch);
    return ['ok'=>$body!==false && $status>=200 && $status<400,'status'=>$status,'ms'=>$ms,'error'=>$error?:null,'body'=>$head?null:$body];
}

function healthProbePath(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_system_health.json';
}

function healthProbe(bool $force): ?array {
    if (!$force) {
        $raw = @file_get_contents(healthProbePath());
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($cached) ? $cached : null;
    }

    $faa = nmsAccessToken(true);
    $metar = ycWeatherProduct('metar', 'LTAI');
    $taf = ycWeatherProduct('taf', 'LTAI');
    $wafs = healthHttp('https://aviationweather.gov/data/products/wafs/', true);
    $adsb = healthHttp('https://api.adsb.lol/v2/point/36.90/30.80/10');
    if (is_string($adsb['body'] ?? null)) {
        $json = json_decode($adsb['body'], true);
        $adsb['aircraft'] = is_array($json['ac'] ?? null) ? count($json['ac']) : null;
        unset($adsb['body']);
    }
    $maplibre = healthHttp('https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/maplibre-gl.js', true);
    $tiles = healthHttp('https://tile.openstreetmap.org/0/0/0.png', true);

    $result = [
        'checkedAt'=>gmdate('c'),
        'faa'=>['ok'=>(bool)($faa['ok']??false),'status'=>$faa['status']??null,'error'=>$faa['error']??null],
        'metar'=>['ok'=>(bool)($metar['ok']??false),'status'=>$metar['upstreamStatus']??null],
        'taf'=>['ok'=>(bool)($taf['ok']??false),'status'=>$taf['upstreamStatus']??null],
        'wafs'=>$wafs,
        'adsb'=>$adsb,
        'maplibre'=>$maplibre,
        'osm'=>$tiles,
    ];

    @file_put_contents(healthProbePath(), json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $result;
}

function healthDatabase(): array {
    $started = microtime(true);
    $pdo = nmsDb();
    $latency = round((microtime(true) - $started) * 1000, 1);
    $cfg = healthLoadConfig();

    $tables = ['notams','nav_points','nav_routes','nav_route_segments','nav_route_memberships','nav_route_geometry','nav_airspaces','nav_airspace_geometry'];
    $counts = [];
    foreach ($tables as $table) {
        try { $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
        catch (Throwable) { $counts[$table] = null; }
    }

    return [
        'ok'=>true,
        'latencyMs'=>$latency,
        'database'=>$cfg['database']??null,
        'user'=>$cfg['user']??null,
        'counts'=>$counts,
    ];
}

function healthLatestNotams(int $limit): array {
    $limit = max(1, min(50, $limit));
    $pdo = nmsDb();
    $sql = "SELECT nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,
        effective_start,effective_end,effective_end_raw,lower_limit,upper_limit,coordinates_raw,radius_nm,status,
        last_updated,notam_text,raw_json
        FROM notams
        WHERE source='FAA_NMS' AND environment='production'
        ORDER BY last_updated DESC
        LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function(array $row): array {
        $raw = json_decode((string)($row['raw_json'] ?? ''), true);
        $ident = trim((string)$row['series']) . trim((string)$row['number']) . '/' . trim((string)$row['year']);
        return [
            'parsed'=>[
                'id'=>$row['nms_id'],
                'ident'=>$ident,
                'type'=>$row['notam_type'],
                'classification'=>$row['classification'],
                'fir'=>$row['affected_fir'],
                'location'=>$row['icao_location'] ?: $row['location'],
                'effectiveStart'=>$row['effective_start'],
                'effectiveEnd'=>$row['effective_end_raw'] ?: $row['effective_end'],
                'lower'=>$row['lower_limit'],
                'upper'=>$row['upper_limit'],
                'coordinates'=>$row['coordinates_raw'],
                'radiusNm'=>$row['radius_nm'],
                'status'=>$row['status'],
                'lastUpdated'=>$row['last_updated'],
                'text'=>$row['notam_text'],
            ],
            'raw'=>is_array($raw) ? $raw : $row['raw_json'],
        ];
    }, $rows);
}

function healthLogs(int $lines): array {
    $dir = dirname(__DIR__, 4) . '/logs/main/notam';
    $files = (array)glob($dir . '/nms-*.log');
    rsort($files);
    $all = [];
    foreach (array_slice($files, 0, 4) as $file) {
        $rows = @file($file, FILE_IGNORE_NEW_LINES);
        if (is_array($rows)) $all = array_merge($all, $rows);
    }
    return array_slice($all, -max(20, min(1000, $lines)));
}

function healthMask(string $value): string {
    if ($value === '') return '';
    $n = strlen($value);
    if ($n <= 4) return str_repeat('*', $n);
    return substr($value, 0, 3) . str_repeat('*', max(4, $n - 5)) . substr($value, -2);
}

function healthSettings(): array {
    $cfg = healthLoadConfig();
    $nms = is_array($cfg['nms'] ?? null) ? $cfg['nms'] : [];
    return [
        'healthKeyConfigured'=>nmsHealthPasswordHash()!=='' || nmsAdminKey()!=='',
        'healthKeyManagedByEnv'=>(bool)(getenv('HEALTH_ADMIN_KEY') ?: getenv('NMS_ADMIN_KEY')),
        'nms'=>[
            'environment'=>$nms['env']??'staging',
            'clientId'=>healthMask((string)($nms['client_id']??'')),
            'clientSecretConfigured'=>trim((string)($nms['client_secret']??''))!=='',
            'managedByEnv'=>(bool)(getenv('NMS_CLIENT_ID') ?: getenv('NMS_CLIENT_SECRET') ?: getenv('NMS_ENV')),
        ],
        'db'=>[
            'host'=>$cfg['host']??'',
            'port'=>$cfg['port']??3306,
            'database'=>$cfg['database']??'',
            'user'=>$cfg['user']??'',
            'passwordConfigured'=>trim((string)($cfg['password']??''))!=='',
        ],
        'notamRetentionDays'=>3,
        'logRetentionDays'=>3,
    ];
}

function healthTestFaa(array $nms): bool {
    if (!function_exists('curl_init')) return false;
    $env = in_array(strtolower((string)($nms['env']??'')), ['prod','production'], true) ? 'production' : 'staging';
    $host = $env === 'production' ? 'https://api-nms.aim.faa.gov' : 'https://api-staging.cgifederal-aim.com';
    $id = trim((string)($nms['client_id']??''));
    $secret = trim((string)($nms['client_secret']??''));
    if ($id === '' || $secret === '') return false;

    $ch = curl_init($host . '/v1/auth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
        CURLOPT_USERPWD=>$id . ':' . $secret,
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return $status >= 200 && $status < 300 && is_array($json) && !empty($json['access_token']);
}

function healthSaveSettings(array $body): array {
    $path = healthConfigPath();
    $cfg = healthLoadConfig();
    $changed = [];

    if (!isset($cfg['nms']) || !is_array($cfg['nms'])) $cfg['nms'] = [];
    $nms = $cfg['nms'];

    if (isset($body['nmsEnvironment'])) {
        $env = strtolower(trim((string)$body['nmsEnvironment']));
        $nms['env'] = in_array($env, ['prod','production'], true) ? 'production' : 'staging';
        $changed[] = 'nmsEnvironment';
    }
    if (trim((string)($body['nmsClientId']??'')) !== '') {
        $nms['client_id'] = trim((string)$body['nmsClientId']);
        $changed[] = 'nmsClientId';
    }
    if (trim((string)($body['nmsClientSecret']??'')) !== '') {
        $nms['client_secret'] = trim((string)$body['nmsClientSecret']);
        $changed[] = 'nmsClientSecret';
    }

    if (array_intersect($changed, ['nmsEnvironment','nmsClientId','nmsClientSecret'])) {
        if (getenv('NMS_CLIENT_ID') || getenv('NMS_CLIENT_SECRET') || getenv('NMS_ENV')) {
            throw new RuntimeException('FAA ayarları environment variable tarafından yönetiliyor.');
        }
        if (!healthTestFaa($nms)) throw new RuntimeException('FAA credentials testi başarısız.');
    }
    $cfg['nms'] = $nms;

    $dbChanged = false;
    foreach (['dbHost'=>'host','dbName'=>'database','dbUser'=>'user'] as $input=>$key) {
        if (trim((string)($body[$input]??'')) !== '') {
            $cfg[$key] = trim((string)$body[$input]);
            $dbChanged = true;
            $changed[] = $input;
        }
    }
    if (isset($body['dbPort']) && is_numeric($body['dbPort'])) {
        $cfg['port'] = (int)$body['dbPort'];
        $dbChanged = true;
        $changed[] = 'dbPort';
    }
    if (trim((string)($body['dbPassword']??'')) !== '') {
        $cfg['password'] = (string)$body['dbPassword'];
        $dbChanged = true;
        $changed[] = 'dbPassword';
    }

    if ($dbChanged) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],(int)$cfg['port'],$cfg['database']),
            $cfg['user'],$cfg['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]
        );
        $pdo->query('SELECT 1')->fetchColumn();
    }

    $newHealthKey = trim((string)($body['healthPassword']??''));
    if ($newHealthKey !== '') {
        if (getenv('HEALTH_ADMIN_KEY') || getenv('NMS_ADMIN_KEY')) {
            throw new RuntimeException('Health şifresi environment variable tarafından yönetiliyor.');
        }
        if (strlen($newHealthKey) < 8) throw new RuntimeException('Health şifresi en az 8 karakter olmalı.');
        if (!isset($cfg['health']) || !is_array($cfg['health'])) $cfg['health'] = [];
        $cfg['health']['password_hash'] = password_hash($newHealthKey, PASSWORD_DEFAULT);
        unset($cfg['health']['admin_key']);
        $changed[] = 'healthPassword';
    }

    $tmp = $path . '.tmp';
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('data.php yazılamadı.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('data.php güncellenemedi.');
    }

    return $changed;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'snapshot')));

try {
    if ($action === 'snapshot') {
        ycApiV1Method('GET');
        $cfg = nmsPrivateConfig();
        $apis = ['navdata','notam','flights','weather','metar','taf','wafs','briefing','modelwx'];
        $apiState = [];
        foreach ($apis as $api) $apiState[$api] = is_file(__DIR__ . '/' . $api . '.php');

        ycApiV1Respond(200, [
            'ok'=>true,
            'generatedAt'=>gmdate('c'),
            'database'=>healthDatabase(),
            'nms'=>nmsHealthLocal($cfg['env']),
            'nmsConfig'=>nmsPublicStatus(),
            'network'=>healthProbe(($_GET['probe']??'0')==='1'),
            'apis'=>$apiState,
            'settings'=>healthSettings(),
        ]);
    }

    if ($action === 'notams') {
        ycApiV1Method('GET');
        ycApiV1Respond(200, ['ok'=>true,'items'=>healthLatestNotams((int)($_GET['limit']??20))]);
    }

    if ($action === 'logs') {
        ycApiV1Method('GET');
        ycApiV1Respond(200, ['ok'=>true,'retentionDays'=>3,'lines'=>healthLogs((int)($_GET['lines']??300))]);
    }

    if ($action === 'settings-save') {
        ycApiV1Method('POST');
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) $body = [];
        ycApiV1Respond(200, ['ok'=>true,'changed'=>healthSaveSettings($body)]);
    }

    if ($action === 'nms-delta') {
        ycApiV1Method('POST');
        $result = nmsRunDeltaSync();
        ycApiV1Respond(($result['ok']??false)?200:502, $result);
    }

    ycApiV1Respond(400, ['ok'=>false,'error'=>'Geçersiz health action.']);
} catch (Throwable $e) {
    ycApiV1Respond(500, ['ok'=>false,'error'=>$e->getMessage()]);
}
