<?php
declare(strict_types=1);

// These browser-origin checks are defence in depth, not client authentication.
function ycRejectRequest(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ycSameOrigin(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && strtolower((string)($parts['host'] ?? '')) === 'yulcaribe.com'
        && (!isset($parts['port']) || $parts['port'] === 443)
        && !isset($parts['user']) && !isset($parts['pass']);
}
header('Vary: Origin, Sec-Fetch-Site, Referer');
header('Cross-Origin-Resource-Policy: same-origin');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
if (($fetchSite !== '' && $fetchSite !== 'same-origin')
    || ($origin !== '' ? !in_array($origin, ['https://yulcaribe.com','https://yulcaribe.com:443'], true)
        : !ycSameOrigin($referer))) {
    ycRejectRequest(403, 'Bu API yalnızca yulcaribe.com üzerinden kullanılabilir.');
}
foreach ($_GET as $value) {
    if (!is_string($value) || strlen($value) > 2048 || str_contains($value, "\0")) {
        ycRejectRequest(400, 'Geçersiz veya çok uzun istek parametresi.');
    }
}
if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','POST'], true)) {
    header('Allow: GET, POST');
    ycRejectRequest(405, 'HTTP method not allowed.');
}


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('X-YC-API-Resource: health');

function out(int $status, array $payload): never {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('[health] JSON response: '.json_last_error_msg());
        $status = 500;
        $json = '{"ok":false,"error":"Response could not be encoded."}';
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}

function method(string $expected): void {
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== strtoupper($expected)) {
        header('Allow: ' . strtoupper($expected));
        out(405, ['ok'=>false,'error'=>'HTTP method not allowed.']);
    }
}

function configPath(): string {
    return dirname(__DIR__, 3) . '/data.php';
}

function loadConfig(): array {
    $path = configPath();
    if (!is_file($path)) throw new RuntimeException('Configuration unavailable.');
    $cfg = require $path;
    if (!is_array($cfg)) throw new RuntimeException('Configuration invalid.');
    return $cfg;
}

function sessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    session_name('yulcaribe_health');
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/main/',
        'secure'=>true,
        'httponly'=>true,
        'samesite'=>'Strict',
    ]);
    session_start();
}

function adminKey(): string {
    $env = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: getenv('NMS_ADMIN_KEY') ?: ''));
    if ($env !== '') return $env;
    try { $cfg = loadConfig(); } catch (Throwable) { return ''; }
    $health = is_array($cfg['health'] ?? null) ? $cfg['health'] : [];
    $nms = is_array($cfg['nms'] ?? null) ? $cfg['nms'] : [];
    $healthKey = trim((string)($health['admin_key'] ?? ''));
    return $healthKey !== '' ? $healthKey : trim((string)($nms['admin_key'] ?? ''));
}

function passwordHashValue(): string {
    try { $cfg = loadConfig(); } catch (Throwable) { return ''; }
    $health = is_array($cfg['health'] ?? null) ? $cfg['health'] : [];
    return trim((string)($health['password_hash'] ?? ''));
}

function verifyPassword(string $provided): bool {
    $provided = trim($provided);
    if ($provided === '') return false;
    $env = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: ''));
    if ($env !== '') return hash_equals($env, $provided);
    $hash = passwordHashValue();
    if ($hash !== '') return password_verify($provided, $hash);
    $legacy = adminKey();
    return $legacy !== '' && hash_equals($legacy, $provided);
}

function credentialVersion(): string {
    $env = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: ''));
    return hash('sha256', $env !== '' ? $env : (passwordHashValue() ?: adminKey()));
}

function authenticated(): bool {
    sessionStart();
    if (($_SESSION['health_authenticated'] ?? false) !== true) return false;
    $now = time();
    $loginAt = (int)($_SESSION['health_login_at'] ?? 0);
    $lastSeen = (int)($_SESSION['health_last_seen'] ?? 0);
    $version = (string)($_SESSION['health_credential_version'] ?? '');
    if (!$loginAt || !$lastSeen || $now-$lastSeen > 1800 || $now-$loginAt > 28800
        || $loginAt > $now || $lastSeen > $now || !hash_equals(credentialVersion(),$version)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['health_last_seen'] = $now;
    return true;
}

function login(string $password): bool {
    sessionStart();
    if (!verifyPassword($password)) {
        usleep(250000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['health_authenticated'] = true;
    $_SESSION['health_login_at'] = time();
    $_SESSION['health_last_seen'] = time();
    $_SESSION['health_credential_version'] = credentialVersion();
    return true;
}

function logout(): void {
    sessionStart();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'=>time()-42000,
            'path'=>$params['path'] ?: '/main/',
            'domain'=>$params['domain'] ?: '',
            'secure'=>(bool)$params['secure'],
            'httponly'=>(bool)$params['httponly'],
            'samesite'=>'Strict',
        ]);
    }
    session_destroy();
}

function pdo(?array $override = null): PDO {
    $cfg = $override ?? loadConfig();
    foreach (['host','port','database','user','password'] as $key) {
        if (!array_key_exists($key, $cfg)) throw new RuntimeException('Database configuration incomplete.');
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']),
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function databaseInfo(): array {
    $started = microtime(true);
    $cfg = loadConfig();
    $db = pdo();
    $db->query('SELECT 1')->fetchColumn();
    $tables = ['notams','nav_points','nav_routes','nav_route_segments','nav_route_memberships','nav_route_availability','nav_route_geometry','nav_airspaces','nav_airspace_geometry'];
    $counts = [];
    foreach ($tables as $table) {
        try { $counts[$table] = (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
        catch (Throwable $e) { error_log('[health-table] ' . $e->getMessage()); $counts[$table] = null; }
    }
    $version = null;
    try { $version = (string)$db->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable) {}
    return [
        'ok'=>true,'latencyMs'=>round((microtime(true)-$started)*1000,1),'serverVersion'=>$version,
        'host'=>$cfg['host'] ?? null,'port'=>$cfg['port'] ?? 3306,'database'=>$cfg['database'] ?? null,
        'user'=>$cfg['user'] ?? null,'counts'=>$counts,
    ];
}

function nmsConfig(): array {
    $cfg = loadConfig();
    $nms = is_array($cfg['nms'] ?? null) ? $cfg['nms'] : [];
    $environment = strtolower(trim((string)(getenv('NMS_ENV') ?: ($nms['env'] ?? 'staging'))));
    $environment = in_array($environment, ['prod','production'], true) ? 'production' : 'staging';
    $id = trim((string)(getenv('NMS_CLIENT_ID') ?: ($nms['client_id'] ?? '')));
    $secret = trim((string)(getenv('NMS_CLIENT_SECRET') ?: ($nms['client_secret'] ?? '')));
    return [
        'environment'=>$environment,'clientId'=>$id,'clientSecret'=>$secret,
        'credentialsConfigured'=>$id !== '' && $secret !== '',
        'managedByEnv'=>(bool)(getenv('NMS_CLIENT_ID') ?: getenv('NMS_CLIENT_SECRET') ?: getenv('NMS_ENV')),
    ];
}

function nmsAuthProbe(?array $override = null): array {
    $cfg = $override ?? nmsConfig();
    $environment = in_array(strtolower((string)($cfg['environment'] ?? '')), ['prod','production'], true) ? 'production' : 'staging';
    $id = trim((string)($cfg['clientId'] ?? ''));
    $secret = trim((string)($cfg['clientSecret'] ?? ''));
    if ($id === '' || $secret === '') return ['ok'=>false,'status'=>0,'error'=>'Credentials missing.'];
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'error'=>'HTTP client unavailable.'];
    $host = $environment === 'production' ? 'https://api-nms.aim.faa.gov' : 'https://api-staging.cgifederal-aim.com';
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
        CURLOPT_USERAGENT=>'YulCaribe-Health/1.0',
    ]);
    $started = microtime(true);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body,true) : null;
    $ok = $status >= 200 && $status < 300 && is_array($json) && !empty($json['access_token']);
    if (!$ok && $error !== '') error_log('[health-faa] ' . $error);
    return ['ok'=>$ok,'status'=>$status,'ms'=>round((microtime(true)-$started)*1000,1),'error'=>$ok?null:'FAA authentication failed.'];
}

function nmsLocal(PDO $db, string $environment): array {
    $state = [];
    try {
        $stmt = $db->prepare("SELECT source,environment,last_successful_sync,last_full_load,last_request_id,last_error,created_at,updated_at FROM notam_sync_state WHERE source='FAA_NMS' AND environment=:environment LIMIT 1");
        $stmt->execute(['environment'=>$environment]);
        $state = $stmt->fetch() ?: [];
    } catch (Throwable $e) { error_log('[health-nms-state] ' . $e->getMessage()); }

    $counts = ['total'=>0,'active'=>0,'future'=>0,'expired'=>0,'cancelled'=>0,'permanent'=>0];
    $latest = null;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) total_count,SUM(status='active') active_count,SUM(status='cancelled') cancelled_count,SUM(effective_start>UTC_TIMESTAMP() AND status<>'cancelled') future_count,SUM(effective_end IS NOT NULL AND effective_end<UTC_TIMESTAMP() AND UPPER(COALESCE(effective_end_raw,''))<>'PERM' AND status<>'cancelled') expired_count,SUM(UPPER(COALESCE(effective_end_raw,''))='PERM') permanent_count,MAX(last_updated) latest_update FROM notams WHERE source='FAA_NMS' AND environment=:environment");
        $stmt->execute(['environment'=>$environment]);
        $row = $stmt->fetch() ?: [];
        $counts = [
            'total'=>(int)($row['total_count'] ?? 0),'active'=>(int)($row['active_count'] ?? 0),
            'future'=>(int)($row['future_count'] ?? 0),'expired'=>(int)($row['expired_count'] ?? 0),
            'cancelled'=>(int)($row['cancelled_count'] ?? 0),'permanent'=>(int)($row['permanent_count'] ?? 0),
        ];
        $latest = $row['latest_update'] ?? null;
    } catch (Throwable $e) { error_log('[health-nms-counts] ' . $e->getMessage()); }

    $last = trim((string)($state['last_successful_sync'] ?? ''));
    $age = null;
    if ($last !== '') { $ts = strtotime($last . ' UTC'); if ($ts !== false) $age = max(0,time()-$ts); }
    $syncHealth = $age === null ? 'unknown' : ($age <= 900 ? 'ok' : ($age <= 1800 ? 'warning' : 'stale'));

    $cronPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_nms' . DIRECTORY_SEPARATOR . 'cron_state_' . $environment . '.json';
    $cronState = null;
    if (is_file($cronPath)) {
        $raw = @file_get_contents($cronPath);
        $decoded = is_string($raw) ? json_decode($raw,true) : null;
        if (is_array($decoded)) $cronState = $decoded;
    }

    return [
        'state'=>$state,'counts'=>$counts,'latestNotamUpdate'=>$latest,
        'syncAgeSeconds'=>$age,'syncHealth'=>$syncHealth,'cronState'=>$cronState,
    ];
}

function jobs(array $local): array {
    $cron = is_array($local['cronState'] ?? null) ? $local['cronState'] : [];
    $updated = trim((string)($cron['updatedAt'] ?? ''));
    $age = null;
    if ($updated !== '') { $ts=strtotime($updated); if($ts!==false)$age=max(0,time()-$ts); }
    $status = $age === null ? 'unknown' : ($age <= 900 ? 'ok' : ($age <= 1800 ? 'warning' : 'error'));
    return [
        'cron'=>[
            'status'=>$status,'updatedAt'=>$updated ?: null,'ageSeconds'=>$age,
            'mode'=>$cron['mode'] ?? null,'ok'=>$cron['ok'] ?? null,'running'=>$cron['running'] ?? null,
            'processed'=>$cron['processed'] ?? null,'received'=>$cron['received'] ?? null,
            'progressPercent'=>$cron['progressPercent'] ?? null,'error'=>$cron['error'] ?? null,'schedule'=>'*/5 * * * *',
        ],
        'notamRetention'=>['days'=>3],
        'logRetention'=>['days'=>3],
    ];
}

function baseUrl(): string {
    return 'https://yulcaribe.com';
}

function httpProbe(string $url, bool $head = false, int $timeout = 10): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'ms'=>null,'error'=>'HTTP client unavailable.'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>'YulCaribe-Health/1.0',
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOBODY=>$head,
        CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json,text/html,image/*;q=0.8,*/*;q=0.5'], str_starts_with($url, baseUrl().'/main/api/') ? ['Origin: https://yulcaribe.com'] : []),
    ]);
    $started=microtime(true);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($error!=='')error_log('[health-http] '.$error);
    return ['ok'=>$body!==false&&$status>=200&&$status<400,'status'=>$status,'ms'=>round((microtime(true)-$started)*1000,1),'body'=>$head?null:$body,'error'=>$body===false?'Request failed.':null];
}

function jsonProbe(string $path, int $timeout = 12): array {
    $probe=httpProbe(baseUrl().$path,false,$timeout);
    $json=is_string($probe['body'] ?? null)?json_decode((string)$probe['body'],true):null;
    $semantic=is_array($json)&&(($json['ok'] ?? false)===true);
    return [
        'ok'=>$probe['ok']&&$semantic,'status'=>$probe['status'],'ms'=>$probe['ms'],
        'error'=>$probe['ok']&&$semantic?null:'Service check failed.',
        'meta'=>is_array($json)?[
            'resource'=>$json['resource'] ?? null,'mode'=>$json['mode'] ?? null,
            'source'=>$json['source'] ?? null,'count'=>$json['count'] ?? $json['total'] ?? $json['records'] ?? null,
            'upstreamStatus'=>$json['upstreamStatus'] ?? null,
        ]:null,
    ];
}

function probePath(): string {
    return rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_system_health.json';
}

function networkProbe(bool $force): ?array {
    if (!$force) {
        $raw=@file_get_contents(probePath());
        $cached=is_string($raw)?json_decode($raw,true):null;
        return is_array($cached)?$cached:null;
    }
    $api=[
        'metartaf'=>jsonProbe('/main/api/metartaf.php?icao=LTAI'),
        'navdata'=>jsonProbe('/main/api/navdata.php?action=health'),
        'notam'=>jsonProbe('/main/api/notam.php?action=health'),
        'wafs'=>jsonProbe('/main/api/wafs.php?action=status&fl=300'),
        'adsb'=>jsonProbe('/main/api/adsb.php?action=status&box=36.500000,37.300000,30.000000,31.200000',14),
    ];
    $mapPage=httpProbe(baseUrl().'/main/map/',false,10);unset($mapPage['body']);
    $mapScript=httpProbe(baseUrl().'/main/map/map.js',true,10);
    $maplibre=httpProbe('https://cdn.jsdelivr.net/npm/maplibre-gl@4.7.1/dist/maplibre-gl.js',true,8);
    $osm=httpProbe('https://tile.openstreetmap.org/0/0/0.png',true,8);
    $wafsFeed=jsonProbe('/main/api/wafs.php?action=health&fl=300',14);
    $faa=nmsAuthProbe();
    $result=['checkedAt'=>gmdate('c'),'api'=>$api,'mapPage'=>$mapPage,'mapScript'=>$mapScript,'maplibre'=>$maplibre,'osm'=>$osm,'wafsFeed'=>$wafsFeed,'faa'=>$faa];
    @file_put_contents(probePath(),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
    return $result;
}

function settings(): array {
    $cfg=loadConfig();$nms=nmsConfig();
    return [
        'healthPasswordConfigured'=>passwordHashValue()!==''||adminKey()!=='',
        'healthPasswordManagedByEnv'=>(bool)getenv('HEALTH_ADMIN_KEY'),
        'nms'=>[
            'environment'=>$nms['environment'],'clientId'=>$nms['clientId'],
            'clientSecretConfigured'=>$nms['clientSecret']!=='','managedByEnv'=>$nms['managedByEnv'],
        ],
        'db'=>[
            'host'=>$cfg['host'] ?? '','port'=>$cfg['port'] ?? 3306,'database'=>$cfg['database'] ?? '',
            'user'=>$cfg['user'] ?? '','passwordConfigured'=>trim((string)($cfg['password'] ?? ''))!=='',
        ],
        'notamRetentionDays'=>3,'logRetentionDays'=>3,
    ];
}

function latestNotams(int $limit): array {
    $limit=max(1,min(50,$limit));$db=pdo();
    $stmt=$db->prepare("SELECT nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,effective_start,effective_end,effective_end_raw,lower_limit,upper_limit,coordinates_raw,radius_nm,status,last_updated,notam_text,raw_json FROM notams WHERE source='FAA_NMS' AND environment='production' ORDER BY last_updated DESC LIMIT {$limit}");
    $stmt->execute();$items=[];
    while($row=$stmt->fetch()){
        $series=strtoupper(trim((string)$row['series']));$number=strtoupper(trim((string)$row['number']));$ident=($number!==''&&$series!==''&&str_starts_with($number,$series))?$number:$series.$number;$year=trim((string)$row['year']);if($year!==''&&!preg_match('/\/\d{2}$/D',$ident))$ident.='/'.substr($year,-2);
        $raw=json_decode((string)($row['raw_json'] ?? ''),true);
        $items[]=['parsed'=>[
            'id'=>$row['nms_id'],'ident'=>$ident ?: $row['nms_id'],'type'=>$row['notam_type'],'classification'=>$row['classification'],
            'fir'=>$row['affected_fir'],'location'=>$row['icao_location'] ?: $row['location'],'effectiveStart'=>$row['effective_start'],
            'effectiveEnd'=>$row['effective_end_raw'] ?: $row['effective_end'],'lower'=>$row['lower_limit'],'upper'=>$row['upper_limit'],
            'coordinates'=>$row['coordinates_raw'],'radiusNm'=>$row['radius_nm'],'status'=>$row['status'],'lastUpdated'=>$row['last_updated'],'text'=>$row['notam_text'],
        ],'raw'=>is_array($raw)?$raw:$row['raw_json']];
    }
    return $items;
}

function logLines(int $lines): array {
    $limit=max(20,min(1000,$lines));$dir=dirname(__DIR__,3).'/logs/main/notam';$files=(array)glob($dir.'/nms-*.log');rsort($files,SORT_STRING);$all=[];
    foreach(array_slice($files,0,4) as$file){$rows=@file($file,FILE_IGNORE_NEW_LINES);if(!is_array($rows))continue;$rows=array_reverse($rows);foreach($rows as$row){$all[]='['.basename($file).'] '.$row;if(count($all)>=$limit)break 2;}}
    return $all;
}

function testFaaSettings(array $nms): bool {
    $probe=nmsAuthProbe([
        'environment'=>$nms['env'] ?? 'staging','clientId'=>$nms['client_id'] ?? '','clientSecret'=>$nms['client_secret'] ?? ''
    ]);
    return (bool)($probe['ok'] ?? false);
}

function saveSettings(array $body): array {
    $path=configPath();$cfg=loadConfig();$changed=[];
    if(!isset($cfg['nms'])||!is_array($cfg['nms']))$cfg['nms']=[];$nms=$cfg['nms'];

    if(trim((string)($body['nmsEnvironment'] ?? ''))!==''){
        $v=strtolower(trim((string)$body['nmsEnvironment']));$v=in_array($v,['prod','production'],true)?'production':'staging';
        if(($nms['env'] ?? 'staging')!==$v){$nms['env']=$v;$changed[]='nmsEnvironment';}
    }
    if(trim((string)($body['nmsClientId'] ?? ''))!==''){$v=trim((string)$body['nmsClientId']);if((string)($nms['client_id'] ?? '')!==$v){$nms['client_id']=$v;$changed[]='nmsClientId';}}
    if(trim((string)($body['nmsClientSecret'] ?? ''))!==''){$nms['client_secret']=trim((string)$body['nmsClientSecret']);$changed[]='nmsClientSecret';}

    if(array_intersect($changed,['nmsEnvironment','nmsClientId','nmsClientSecret'])){
        if(getenv('NMS_CLIENT_ID')||getenv('NMS_CLIENT_SECRET')||getenv('NMS_ENV'))throw new RuntimeException('FAA settings are managed by environment variables.');
        if(!testFaaSettings($nms))throw new RuntimeException('FAA credential test failed.');
    }
    $cfg['nms']=$nms;

    $dbChanged=false;
    foreach(['dbHost'=>'host','dbName'=>'database','dbUser'=>'user'] as$input=>$key){$v=trim((string)($body[$input] ?? ''));if($v!==''&&(string)($cfg[$key] ?? '')!==$v){$cfg[$key]=$v;$dbChanged=true;$changed[]=$input;}}
    if(isset($body['dbPort'])&&is_numeric($body['dbPort'])){$v=(int)$body['dbPort'];if((int)($cfg['port'] ?? 3306)!==$v){$cfg['port']=$v;$dbChanged=true;$changed[]='dbPort';}}
    if(trim((string)($body['dbPassword'] ?? ''))!==''){$cfg['password']=(string)$body['dbPassword'];$dbChanged=true;$changed[]='dbPassword';}
    if($dbChanged){$test=pdo($cfg);$test->query('SELECT 1')->fetchColumn();}

    $newPassword=trim((string)($body['healthPassword'] ?? ''));
    if($newPassword!==''){
        if(getenv('HEALTH_ADMIN_KEY'))throw new RuntimeException('Health password is managed by environment variable.');
        if(strlen($newPassword)<8)throw new RuntimeException('Health password must be at least 8 characters.');
        if(!isset($cfg['health'])||!is_array($cfg['health']))$cfg['health']=[];
        $cfg['health']['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT);unset($cfg['health']['admin_key']);$changed[]='healthPassword';
    }
    if(!$changed)return[];

    $tmp=$path.'.tmp.'.getmypid();$php="<?php\nreturn ".var_export($cfg,true).";\n";
    if(@file_put_contents($tmp,$php,LOCK_EX)===false)throw new RuntimeException('Configuration write failed.');
    @chmod($tmp,0600);if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Configuration update failed.');}
    return$changed;
}

function revealSecret(string $kind,string $password): string {
    if(!verifyPassword($password)){usleep(250000);throw new RuntimeException('Password verification failed.');}
    $cfg=loadConfig();$nms=is_array($cfg['nms'] ?? null)?$cfg['nms']:[];
    if($kind==='db-password')return(string)($cfg['password'] ?? '');
    if($kind==='faa-client-secret')return(string)(getenv('NMS_CLIENT_SECRET') ?: ($nms['client_secret'] ?? ''));
    throw new RuntimeException('Unknown secret.');
}

function runDeltaCron(): array {
    $db=pdo();$cfg=nmsConfig();$environment=$cfg['environment'];
    $stmt=$db->prepare("SELECT last_full_load FROM notam_sync_state WHERE source='FAA_NMS' AND environment=:environment LIMIT 1");$stmt->execute(['environment'=>$environment]);$lastFull=$stmt->fetchColumn();
    $countStmt=$db->prepare("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment=:environment");$countStmt->execute(['environment'=>$environment]);$count=(int)$countStmt->fetchColumn();
    if($count===0||!$lastFull)throw new RuntimeException('FAA baseline is not ready; scheduled cron must complete Initial Load first.');
    if(!function_exists('exec'))throw new RuntimeException('Server does not allow manual cron execution.');
    $output=[];$code=1;$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/cron.php').' 2>&1';
    @exec($command,$output,$code);
    if($code!==0)throw new RuntimeException('NMS cron run failed.');
    $local=nmsLocal($db,$environment);
    return ['ok'=>true,'cronState'=>$local['cronState'],'syncHealth'=>$local['syncHealth'],'syncAgeSeconds'=>$local['syncAgeSeconds']];
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16384) out(413,['ok'=>false,'error'=>'Request body too large.']);
$action=strtolower(trim((string)($_GET['action'] ?? 'session')));

try {
    if($action==='session'){
        method('GET');
        out(200,['ok'=>true,'authenticated'=>authenticated()]);
    }
    if($action==='login'){
        method('POST');$body=json_decode((string)file_get_contents('php://input',false,null,0,16385),true);if(!is_array($body))$body=[];
        if(!is_string($body['password'] ?? null) || strlen($body['password'])>4096) out(400,['ok'=>false,'error'=>'Invalid password input.']);
        if(!login($body['password']))out(401,['ok'=>false,'error'=>'Invalid Health password.']);
        out(200,['ok'=>true,'authenticated'=>true]);
    }
    if($action==='logout'){
        method('POST');logout();out(200,['ok'=>true,'authenticated'=>false]);
    }

    if(!authenticated())out(403,['ok'=>false,'error'=>'Health authentication required.']);

    if($action==='snapshot'){
        method('GET');
        $db=pdo();$cfg=nmsConfig();$local=nmsLocal($db,$cfg['environment']);
        $apis=['metartaf','notam','navdata','wafs','adsb','health','cron'];$files=[];foreach($apis as$api)$files[$api]=is_file(__DIR__.'/'.$api.'.php');
        out(200,[
            'ok'=>true,'generatedAt'=>gmdate('c'),'php'=>['version'=>PHP_VERSION],
            'database'=>databaseInfo(),'nms'=>$local,
            'nmsConfig'=>[
                'environment'=>$cfg['environment'],'credentialsConfigured'=>$cfg['credentialsConfigured'],'managedByEnv'=>$cfg['managedByEnv']
            ],
            'network'=>networkProbe(($_GET['probe'] ?? '0')==='1'),'apiFiles'=>$files,'jobs'=>jobs($local),'settings'=>settings(),
        ]);
    }
    if($action==='notams'){
        method('GET');out(200,['ok'=>true,'items'=>latestNotams((int)($_GET['limit'] ?? 20))]);
    }
    if($action==='logs'){
        method('GET');out(200,['ok'=>true,'retentionDays'=>3,'lines'=>logLines((int)($_GET['lines'] ?? 300))]);
    }
    if($action==='settings-save'){
        method('POST');$body=json_decode((string)file_get_contents('php://input',false,null,0,16385),true);if(!is_array($body))$body=[];
        out(200,['ok'=>true,'changed'=>saveSettings($body)]);
    }
    if($action==='secret-reveal'){
        method('POST');$body=json_decode((string)file_get_contents('php://input',false,null,0,16385),true);if(!is_array($body))$body=[];
        $kind=trim((string)($body['kind'] ?? ''));$secret=revealSecret($kind,(string)($body['password'] ?? ''));
        out(200,['ok'=>true,'kind'=>$kind,'secret'=>$secret,'expiresInSeconds'=>30]);
    }
    if($action==='nms-delta'){
        method('POST');out(200,runDeltaCron());
    }
    out(400,['ok'=>false,'error'=>'Unknown Health action.']);
} catch(Throwable $e) {
    error_log('[health] '.$e->getMessage());
    $message = match($action) {
        'settings-save'=>'Settings could not be saved.',
        'secret-reveal'=>'Secret could not be revealed.',
        'nms-delta'=>'NMS sync could not be started.',
        default=>'Health request could not be completed.',
    };
    out(500,['ok'=>false,'error'=>$message]);
}
