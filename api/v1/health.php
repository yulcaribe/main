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

function healthBaseUrl(): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'yulcaribe.com'));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) $host = 'yulcaribe.com';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function healthHttp(string $url, bool $head = false, int $timeout = 10): array {
    if (!function_exists('curl_init')) {
        return ['ok'=>false,'status'=>0,'ms'=>null,'error'=>'cURL yok','body'=>null];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_USERAGENT=>'YulCaribe-Health/1.0',
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_NOBODY=>$head,
        CURLOPT_HTTPHEADER=>['Accept: application/json,text/html,image/*;q=0.8,*/*;q=0.5'],
    ]);

    $started = microtime(true);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    $ms = round((microtime(true) - $started) * 1000, 1);
    curl_close($ch);

    return [
        'ok'=>$body !== false && $status >= 200 && $status < 400,
        'status'=>$status,
        'ms'=>$ms,
        'contentType'=>$contentType ?: null,
        'error'=>$error ?: null,
        'body'=>$head ? null : $body,
    ];
}

function healthJsonProbe(string $path, int $timeout = 12): array {
    $probe = healthHttp(healthBaseUrl() . $path, false, $timeout);
    $json = is_string($probe['body'] ?? null) ? json_decode((string)$probe['body'], true) : null;
    $semanticOk = is_array($json) && (($json['ok'] ?? false) === true);

    return [
        'ok'=>$probe['ok'] && $semanticOk,
        'status'=>$probe['status'],
        'ms'=>$probe['ms'],
        'error'=>$probe['error'] ?: (is_array($json) ? ($json['error'] ?? null) : 'JSON yanıtı geçersiz.'),
        'meta'=>is_array($json) ? [
            'resource'=>$json['resource'] ?? null,
            'mode'=>$json['mode'] ?? null,
            'source'=>$json['source'] ?? ($json['_proxy']['source'] ?? null),
            'count'=>$json['count'] ?? $json['total'] ?? (is_array($json['ac'] ?? null) ? count($json['ac']) : null),
            'upstreamStatus'=>$json['upstreamStatus'] ?? ($json['_proxy']['upstreamStatus'] ?? null),
        ] : null,
    ];
}

function healthProbePath(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'yulcaribe_system_health.json';
}

function healthProbe(bool $force): ?array {
    if (!$force) {
        $raw = @file_get_contents(healthProbePath());
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($cached) ? $cached : null;
    }

    $faa = nmsAccessToken(true);

    $api = [
        'index'=>healthJsonProbe('/main/api/v1/'),
        'navdata'=>healthJsonProbe('/main/api/v1/navdata.php?action=health'),
        'notam'=>healthJsonProbe('/main/api/v1/notam.php?action=map&z=3&west=29&south=36&east=32&north=38'),
        'weather'=>healthJsonProbe('/main/api/v1/weather.php?icao=LTAI'),
        'metar'=>healthJsonProbe('/main/api/v1/metar.php?icao=LTAI'),
        'taf'=>healthJsonProbe('/main/api/v1/taf.php?icao=LTAI'),
        'wafs'=>healthJsonProbe('/main/api/v1/wafs.php?action=status&fl=300'),
        'modelwx'=>healthJsonProbe('/main/api/v1/modelwx.php?action=status&fl=360&left=25&right=45&bottom=30&top=45'),
        'flights'=>healthJsonProbe('/main/api/v1/flights.php?lat=36.90&lon=30.80&radius=10'),
    ];

    $mapPage = healthHttp(healthBaseUrl() . '/main/map/', false, 10);
    $mapScript = healthHttp(healthBaseUrl() . '/main/map/map.js', true, 10);
    unset($mapPage['body'], $mapScript['body']);

    $maplibre = healthHttp('https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/maplibre-gl.js', true, 8);
    $leaflet = healthHttp('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js', true, 8);
    $osm = healthHttp('https://tile.openstreetmap.org/0/0/0.png', true, 8);
    $wafsUpstream = healthHttp('https://aviationweather.gov/data/products/wafs/', true, 8);

    unset($maplibre['body'], $leaflet['body'], $osm['body'], $wafsUpstream['body']);

    $result = [
        'checkedAt'=>gmdate('c'),
        'faa'=>[
            'ok'=>(bool)($faa['ok'] ?? false),
            'status'=>$faa['status'] ?? null,
            'error'=>$faa['error'] ?? null,
        ],
        'api'=>$api,
        'mapPage'=>$mapPage,
        'mapScript'=>$mapScript,
        'maplibre'=>$maplibre,
        'leaflet'=>$leaflet,
        'osm'=>$osm,
        'wafsUpstream'=>$wafsUpstream,
    ];

    @file_put_contents(
        healthProbePath(),
        json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    return $result;
}

function healthDatabase(): array {
    $started = microtime(true);
    $pdo = nmsDb();
    $latency = round((microtime(true) - $started) * 1000, 1);
    $cfg = healthLoadConfig();

    $tables = [
        'notams',
        'nav_points',
        'nav_routes',
        'nav_route_segments',
        'nav_route_memberships',
        'nav_route_availability',
        'nav_route_geometry',
        'nav_airspaces',
        'nav_airspace_geometry',
    ];

    $counts = [];
    foreach ($tables as $table) {
        try {
            $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        } catch (Throwable) {
            $counts[$table] = null;
        }
    }

    $serverVersion = null;
    try {
        $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable) {}

    return [
        'ok'=>true,
        'latencyMs'=>$latency,
        'serverVersion'=>$serverVersion,
        'host'=>$cfg['host'] ?? null,
        'port'=>$cfg['port'] ?? 3306,
        'database'=>$cfg['database'] ?? null,
        'user'=>$cfg['user'] ?? null,
        'counts'=>$counts,
    ];
}

function healthLatestNotams(int $limit): array {
    $limit = max(1, min(50, $limit));
    $pdo = nmsDb();
    $cfg = nmsPrivateConfig();
    $environment = $cfg['env'];

    $sql = "SELECT nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,
        effective_start,effective_end,effective_end_raw,lower_limit,upper_limit,coordinates_raw,radius_nm,status,
        last_updated,notam_text,raw_json
        FROM notams
        WHERE source='FAA_NMS' AND environment=:environment
        ORDER BY last_updated DESC
        LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['environment'=>$environment]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function(array $row): array {
        $raw = json_decode((string)($row['raw_json'] ?? ''), true);
        $ident = trim((string)$row['series'])
            . trim((string)$row['number'])
            . '/'
            . trim((string)$row['year']);

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
        if (is_array($rows)) {
            foreach ($rows as $row) $all[] = '[' . basename($file) . '] ' . $row;
        }
    }

    return array_slice($all, -max(20, min(1000, $lines)));
}

function healthSettings(): array {
    $cfg = healthLoadConfig();
    $nms = is_array($cfg['nms'] ?? null) ? $cfg['nms'] : [];

    $clientId = trim((string)(getenv('NMS_CLIENT_ID') ?: ($nms['client_id'] ?? '')));

    return [
        'healthPasswordConfigured'=>nmsHealthPasswordHash() !== '' || nmsAdminKey() !== '',
        'healthPasswordManagedByEnv'=>(bool)getenv('HEALTH_ADMIN_KEY'),
        'nms'=>[
            'environment'=>strtolower(trim((string)(getenv('NMS_ENV') ?: ($nms['env'] ?? 'staging')))),
            'clientId'=>$clientId,
            'clientSecretConfigured'=>trim((string)(getenv('NMS_CLIENT_SECRET') ?: ($nms['client_secret'] ?? ''))) !== '',
            'managedByEnv'=>(bool)(getenv('NMS_CLIENT_ID') ?: getenv('NMS_CLIENT_SECRET') ?: getenv('NMS_ENV')),
        ],
        'db'=>[
            'host'=>$cfg['host'] ?? '',
            'port'=>$cfg['port'] ?? 3306,
            'database'=>$cfg['database'] ?? '',
            'user'=>$cfg['user'] ?? '',
            'passwordConfigured'=>trim((string)($cfg['password'] ?? '')) !== '',
        ],
        'notamRetentionDays'=>3,
        'logRetentionDays'=>3,
    ];
}

function healthJobs(array $nmsLocal): array {
    $cron = is_array($nmsLocal['cronState'] ?? null) ? $nmsLocal['cronState'] : [];
    $updatedAt = trim((string)($cron['updatedAt'] ?? ''));
    $ageSeconds = null;

    if ($updatedAt !== '') {
        $ts = strtotime($updatedAt);
        if ($ts !== false) $ageSeconds = max(0, time() - $ts);
    }

    $status = 'unknown';
    if ($ageSeconds !== null) {
        if ($ageSeconds <= 900) $status = 'ok';
        elseif ($ageSeconds <= 1800) $status = 'warning';
        else $status = 'error';
    }

    return [
        'cron'=>[
            'status'=>$status,
            'updatedAt'=>$updatedAt ?: null,
            'ageSeconds'=>$ageSeconds,
            'mode'=>$cron['mode'] ?? null,
            'ok'=>$cron['ok'] ?? null,
            'running'=>$cron['running'] ?? null,
            'processed'=>$cron['processed'] ?? null,
            'received'=>$cron['received'] ?? null,
            'error'=>$cron['error'] ?? null,
            'schedule'=>'*/5 * * * *',
        ],
        'notamRetention'=>[
            'days'=>3,
            'last'=>$nmsLocal['retentionCleanup'] ?? null,
        ],
        'logRetention'=>[
            'days'=>3,
            'directory'=>dirname(__DIR__, 4) . '/logs/main/notam',
        ],
    ];
}

function healthTestFaa(array $nms): bool {
    if (!function_exists('curl_init')) return false;

    $env = in_array(strtolower((string)($nms['env'] ?? '')), ['prod','production'], true)
        ? 'production'
        : 'staging';

    $host = $env === 'production'
        ? 'https://api-nms.aim.faa.gov'
        : 'https://api-staging.cgifederal-aim.com';

    $id = trim((string)($nms['client_id'] ?? ''));
    $secret = trim((string)($nms['client_secret'] ?? ''));
    if ($id === '' || $secret === '') return false;

    $ch = curl_init($host . '/v1/auth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
        CURLOPT_USERPWD=>$id . ':' . $secret,
        CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER=>[
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    return $status >= 200
        && $status < 300
        && is_array($json)
        && !empty($json['access_token']);
}

function healthSaveSettings(array $body): array {
    $path = healthConfigPath();
    $cfg = healthLoadConfig();
    $changed = [];

    if (!isset($cfg['nms']) || !is_array($cfg['nms'])) $cfg['nms'] = [];
    $nms = $cfg['nms'];

    if (isset($body['nmsEnvironment']) && trim((string)$body['nmsEnvironment']) !== '') {
        $env = strtolower(trim((string)$body['nmsEnvironment']));
        $nextEnv = in_array($env, ['prod','production'], true) ? 'production' : 'staging';
        if (($nms['env'] ?? 'staging') !== $nextEnv) {
            $nms['env'] = $nextEnv;
            $changed[] = 'nmsEnvironment';
        }
    }

    if (trim((string)($body['nmsClientId'] ?? '')) !== '') {
        $next = trim((string)$body['nmsClientId']);
        if ((string)($nms['client_id'] ?? '') !== $next) {
            $nms['client_id'] = $next;
            $changed[] = 'nmsClientId';
        }
    }

    if (trim((string)($body['nmsClientSecret'] ?? '')) !== '') {
        $nms['client_secret'] = trim((string)$body['nmsClientSecret']);
        $changed[] = 'nmsClientSecret';
    }

    if (array_intersect($changed, ['nmsEnvironment','nmsClientId','nmsClientSecret'])) {
        if (getenv('NMS_CLIENT_ID') || getenv('NMS_CLIENT_SECRET') || getenv('NMS_ENV')) {
            throw new RuntimeException('FAA ayarları environment variable tarafından yönetiliyor.');
        }
        if (!healthTestFaa($nms)) {
            throw new RuntimeException('FAA credentials testi başarısız.');
        }
    }
    $cfg['nms'] = $nms;

    $dbChanged = false;
    foreach (['dbHost'=>'host','dbName'=>'database','dbUser'=>'user'] as $input=>$key) {
        $next = trim((string)($body[$input] ?? ''));
        if ($next !== '' && (string)($cfg[$key] ?? '') !== $next) {
            $cfg[$key] = $next;
            $dbChanged = true;
            $changed[] = $input;
        }
    }

    if (isset($body['dbPort']) && is_numeric($body['dbPort'])) {
        $nextPort = (int)$body['dbPort'];
        if ((int)($cfg['port'] ?? 3306) !== $nextPort) {
            $cfg['port'] = $nextPort;
            $dbChanged = true;
            $changed[] = 'dbPort';
        }
    }

    if (trim((string)($body['dbPassword'] ?? '')) !== '') {
        $cfg['password'] = (string)$body['dbPassword'];
        $dbChanged = true;
        $changed[] = 'dbPassword';
    }

    if ($dbChanged) {
        $test = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                (int)$cfg['port'],
                $cfg['database']
            ),
            $cfg['user'],
            $cfg['password'],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT=>5,
            ]
        );
        $test->query('SELECT 1')->fetchColumn();
    }

    $newHealthPassword = trim((string)($body['healthPassword'] ?? ''));
    if ($newHealthPassword !== '') {
        if (getenv('HEALTH_ADMIN_KEY')) {
            throw new RuntimeException('Health şifresi environment variable tarafından yönetiliyor.');
        }
        if (strlen($newHealthPassword) < 8) {
            throw new RuntimeException('Health şifresi en az 8 karakter olmalı.');
        }

        if (!isset($cfg['health']) || !is_array($cfg['health'])) $cfg['health'] = [];
        $cfg['health']['password_hash'] = password_hash($newHealthPassword, PASSWORD_DEFAULT);
        unset($cfg['health']['admin_key']);
        $changed[] = 'healthPassword';
    }

    if (!$changed) return [];

    $tmp = $path . '.tmp.' . getmypid();
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";

    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new RuntimeException('data.php yazılamadı.');
    }

    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('data.php güncellenemedi.');
    }

    return $changed;
}

function healthRevealSecret(string $kind, string $password): string {
    if (!nmsHealthVerifyPassword($password)) {
        usleep(250000);
        throw new RuntimeException('Health şifresi yanlış.');
    }

    $cfg = healthLoadConfig();
    $nms = is_array($cfg['nms'] ?? null) ? $cfg['nms'] : [];

    if ($kind === 'db-password') {
        return (string)($cfg['password'] ?? '');
    }

    if ($kind === 'faa-client-secret') {
        return (string)(getenv('NMS_CLIENT_SECRET') ?: ($nms['client_secret'] ?? ''));
    }

    throw new RuntimeException('Bilinmeyen secret.');
}

$action = strtolower(trim((string)($_GET['action'] ?? 'snapshot')));

try {
    if ($action === 'snapshot') {
        ycApiV1Method('GET');

        $cfg = nmsPrivateConfig();
        $nmsLocal = nmsHealthLocal($cfg['env']);

        $apis = [
            'index',
            'navdata',
            'notam',
            'flights',
            'weather',
            'metar',
            'taf',
            'wafs',
            'briefing',
            'modelwx',
            'health',
        ];

        $apiFiles = [];
        foreach ($apis as $api) {
            $apiFiles[$api] = is_file(__DIR__ . '/' . $api . '.php');
        }

        ycApiV1Respond(200, [
            'ok'=>true,
            'generatedAt'=>gmdate('c'),
            'database'=>healthDatabase(),
            'nms'=>$nmsLocal,
            'nmsConfig'=>nmsPublicStatus(),
            'network'=>healthProbe(($_GET['probe'] ?? '0') === '1'),
            'apiFiles'=>$apiFiles,
            'jobs'=>healthJobs($nmsLocal),
            'settings'=>healthSettings(),
        ]);
    }

    if ($action === 'notams') {
        ycApiV1Method('GET');
        ycApiV1Respond(200, [
            'ok'=>true,
            'items'=>healthLatestNotams((int)($_GET['limit'] ?? 20)),
        ]);
    }

    if ($action === 'logs') {
        ycApiV1Method('GET');
        ycApiV1Respond(200, [
            'ok'=>true,
            'retentionDays'=>3,
            'lines'=>healthLogs((int)($_GET['lines'] ?? 300)),
        ]);
    }

    if ($action === 'settings-save') {
        ycApiV1Method('POST');
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) $body = [];

        ycApiV1Respond(200, [
            'ok'=>true,
            'changed'=>healthSaveSettings($body),
        ]);
    }

    if ($action === 'secret-reveal') {
        ycApiV1Method('POST');
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) $body = [];

        $kind = trim((string)($body['kind'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $secret = healthRevealSecret($kind, $password);

        ycApiV1Respond(200, [
            'ok'=>true,
            'kind'=>$kind,
            'secret'=>$secret,
            'expiresInSeconds'=>30,
        ]);
    }

    if ($action === 'nms-delta') {
        ycApiV1Method('POST');
        $result = nmsRunDeltaSync();
        ycApiV1Respond(($result['ok'] ?? false) ? 200 : 502, $result);
    }

    ycApiV1Respond(400, ['ok'=>false,'error'=>'Geçersiz health action.']);
} catch (Throwable $e) {
    ycApiV1Respond(500, ['ok'=>false,'error'=>$e->getMessage()]);
}
