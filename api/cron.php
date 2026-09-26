<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const NMS_USER_AGENT = 'YulCaribe-NMS/1.0 (+https://yulcaribe.com)';

function nmsPrivateConfig(): array {
    $fileConfig = [];
    $configPath = dirname(__DIR__, 3) . '/data.php';
    if (is_file($configPath)) {
        $root = require $configPath;
        if (is_array($root) && isset($root['nms']) && is_array($root['nms'])) $fileConfig = $root['nms'];
    }
    $env = strtolower(trim((string)(getenv('NMS_ENV') ?: ($fileConfig['env'] ?? 'staging'))));
    $env = in_array($env, ['prod', 'production'], true) ? 'production' : 'staging';
    $hosts = ['staging' => 'https://api-staging.cgifederal-aim.com', 'production' => 'https://api-nms.aim.faa.gov'];
    $host = $hosts[$env];
    return [
        'env' => $env,
        'host' => $host,
        'api_base' => $host . '/nmsapi/v1',
        'auth_url' => $host . '/v1/auth/token',
        'client_id' => trim((string)(getenv('NMS_CLIENT_ID') ?: ($fileConfig['client_id'] ?? ''))),
        'client_secret' => trim((string)(getenv('NMS_CLIENT_SECRET') ?: ($fileConfig['client_secret'] ?? ''))),
        'timeout' => 120,
    ];
}

function nmsCacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_nms';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function nmsTokenCachePath(array $cfg): string {
    return nmsCacheDir() . DIRECTORY_SEPARATOR . 'token_' . sha1($cfg['env'] . '|' . $cfg['client_id']) . '.json';
}

function nmsReadCachedToken(array $cfg): ?array {
    if ($cfg['client_id'] === '') return null;
    $path = nmsTokenCachePath($cfg);
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) return null;
    $token = (string)($data['accessToken'] ?? '');
    $expiresAt = (int)($data['expiresAt'] ?? 0);
    if ($token === '' || $expiresAt <= time() + 60) return null;
    return ['access_token' => $token, 'expires_at' => $expiresAt, 'cache' => true];
}

function nmsWriteCachedToken(array $cfg, string $token, int $expiresIn): void {
    if ($token === '') return;
    $path = nmsTokenCachePath($cfg);
    @file_put_contents($path, json_encode([
        'accessToken' => $token,
        'expiresAt' => time() + max(1, $expiresIn),
        'savedAt' => time(),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path, 0600);
}

function nmsAccessToken(bool $forceRefresh = false): array {
    $cfg = nmsPrivateConfig();
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') return ['ok' => false, 'status' => 503, 'error' => 'FAA NMS credentials are not configured.'];
    if (!$forceRefresh) {
        $cached = nmsReadCachedToken($cfg);
        if ($cached !== null) return ['ok' => true] + $cached;
    }
    if (!function_exists('curl_init')) return ['ok' => false, 'status' => 500, 'error' => 'PHP cURL extension is not enabled.'];
    $ch = curl_init($cfg['auth_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials'], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_USERPWD => $cfg['client_id'] . ':' . $cfg['client_secret'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT => NMS_USER_AGENT,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) return ['ok' => false, 'status' => $status ?: 502, 'error' => $error !== '' ? $error : ('NMS auth HTTP ' . $status)];
    $data = json_decode((string)$body, true);
    if (!is_array($data)) return ['ok' => false, 'status' => 502, 'error' => 'NMS authentication response is not valid JSON.'];
    $token = trim((string)($data['access_token'] ?? ''));
    $expiresIn = (int)($data['expires_in'] ?? 0);
    if ($token === '') return ['ok' => false, 'status' => 502, 'error' => 'NMS authentication response did not contain an access_token.'];
    nmsWriteCachedToken($cfg, $token, $expiresIn > 0 ? $expiresIn : 1799);
    return ['ok' => true, 'access_token' => $token, 'expires_at' => time() + ($expiresIn > 0 ? $expiresIn : 1799), 'cache' => false];
}

function nmsGet(string $path, array $query = [], ?string $responseFormat = 'GEOJSON', bool $retryAuth = true): array {
    $cfg = nmsPrivateConfig();
    $auth = nmsAccessToken(false);
    if (!($auth['ok'] ?? false)) return $auth;
    $path = '/' . ltrim($path, '/');
    $url = $cfg['api_base'] . $path;
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headers = ['Authorization: Bearer ' . $auth['access_token'], 'Accept: application/json, application/geo+json, application/octet-stream;q=0.8, */*;q=0.5'];
    if ($responseFormat !== null) {
        $format = strtoupper(trim($responseFormat));
        if (!in_array($format, ['AIXM', 'GEOJSON'], true)) return ['ok' => false, 'status' => 400, 'error' => 'Invalid nmsResponseFormat.'];
        $headers[] = 'nmsResponseFormat: ' . $format;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => NMS_USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($status === 401 && $retryAuth) {
        $fresh = nmsAccessToken(true);
        if (!($fresh['ok'] ?? false)) return $fresh;
        return nmsGet($path, $query, $responseFormat, false);
    }
    if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) return ['ok' => false, 'status' => $status ?: 502, 'error' => $error !== '' ? $error : ('NMS HTTP ' . $status)];
    $decoded = json_decode((string)$body, true);
    $isJson = is_array($decoded) || trim((string)$body) === 'null';
    return ['ok' => true, 'status' => $status, 'contentType' => $contentType, 'data' => $isJson ? $decoded : null, 'body' => $isJson ? null : (string)$body];
}

function nmsDownloadToFile(string $path, string $destination, bool $retryAuth = true): array {
    $cfg = nmsPrivateConfig();
    $auth = nmsAccessToken(false);
    if (!($auth['ok'] ?? false)) return $auth;
    $path = '/' . ltrim($path, '/');
    $url = $cfg['api_base'] . $path;
    $fh = @fopen($destination, 'wb');
    if (!$fh) return ['ok' => false, 'status' => 500, 'error' => 'Temporary NMS download file could not be opened.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $auth['access_token'], 'Accept: application/octet-stream, */*;q=0.5'],
        CURLOPT_USERAGENT => NMS_USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
    ]);
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fh);
    if ($status === 401 && $retryAuth) {
        @unlink($destination);
        $fresh = nmsAccessToken(true);
        if (!($fresh['ok'] ?? false)) return $fresh;
        return nmsDownloadToFile($path, $destination, false);
    }
    if ($errno !== 0 || $ok === false || $status < 200 || $status >= 300) {
        @unlink($destination);
        return ['ok' => false, 'status' => $status ?: 502, 'error' => $error !== '' ? $error : ('NMS content HTTP ' . $status)];
    }
    $bytes = is_file($destination) ? (int)filesize($destination) : 0;
    if ($bytes <= 0) {
        @unlink($destination);
        return ['ok' => false, 'status' => 502, 'error' => 'NMS content download was empty.'];
    }
    return ['ok' => true, 'status' => $status, 'contentType' => $contentType, 'bytes' => $bytes, 'path' => $destination];
}

function nmsDb(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL is not enabled.');
    $configPath = dirname(__DIR__, 3) . '/data.php';
    if (!is_file($configPath)) throw new RuntimeException('Database configuration was not found.');
    $cfg = require $configPath;
    if (!is_array($cfg)) throw new RuntimeException('Database configuration is invalid.');
    foreach (['host', 'port', 'database', 'user', 'password'] as $key) if (!array_key_exists($key, $cfg)) throw new RuntimeException('Database configuration is incomplete.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']), $cfg['user'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function nmsStoreText(mixed $value): ?string {
    if ($value === null) return null;
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_scalar($value)) { $text = trim((string)$value); return $text === '' ? null : $text; }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}
function nmsStoreShortText(mixed $value, int $maxLength): ?string { $text = nmsStoreText($value); if ($text === null) return null; return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength); }
function nmsStoreDate(mixed $value): ?string { $raw = nmsStoreText($value); if ($raw === null || strtoupper($raw) === 'PERM') return null; try { return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable) { return null; } }
function nmsStoreInt(mixed $value): ?int { if ($value === null || $value === '') return null; if (is_numeric($value)) return (int)$value; $text = nmsStoreText($value); return $text !== null && preg_match('/-?\d+/', $text, $m) ? (int)$m[0] : null; }
function nmsStoreFloat(mixed $value): ?float { return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null; }
function nmsCanonicalClassification(mixed $value): ?string { $text = strtoupper(trim((string)($value ?? ''))); if ($text === '') return null; return match ($text) { 'DOM' => 'DOMESTIC', 'INTL' => 'INTERNATIONAL', 'MIL' => 'MILITARY', 'LMIL', 'LOCAL_MIL' => 'LOCAL_MILITARY', default => $text }; }
function nmsRecordStatus(?string $type, ?string $effectiveStart, ?string $effectiveEndRaw): string { if (strtoupper((string)$type) === 'C') return 'cancelled'; $now=time(); $start=$effectiveStart!==null?strtotime($effectiveStart.' UTC'):false; $endRaw=strtoupper(trim((string)$effectiveEndRaw)); $end=($endRaw!==''&&$endRaw!=='PERM')?strtotime((string)$effectiveEndRaw):false; if($start!==false&&$start>$now)return'inactive'; if($end!==false&&$end<$now)return'inactive'; if($start!==false||$endRaw==='PERM'||$end!==false)return'active'; return'unknown'; }

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
    $geometryJson = isset($feature['geometry']) && is_array($feature['geometry']) ? json_encode($feature['geometry'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $rawJson = json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawJson === false) $rawJson = null;
    return [
        'nms_id'=>$nmsId,'series'=>nmsStoreShortText($notam['series']??null,10),'number'=>nmsStoreShortText($notam['number']??null,30),'year'=>nmsStoreInt($notam['year']??null),'notam_type'=>$type,'classification'=>nmsCanonicalClassification($notam['classification']??null),'affected_fir'=>nmsStoreShortText($notam['affectedFir']??null,20),'location'=>nmsStoreShortText($notam['location']??null,20),'icao_location'=>nmsStoreShortText($notam['icaoLocation']??null,20),'account_id'=>nmsStoreShortText($notam['accountId']??null,50),'selection_code'=>nmsStoreShortText($notam['selectionCode']??null,30),'traffic'=>nmsStoreShortText($notam['traffic']??null,20),'purpose'=>nmsStoreShortText($notam['purpose']??null,20),'scope'=>nmsStoreShortText($notam['scope']??null,20),'minimum_fl'=>nmsStoreInt($notam['minimumFl']??null),'maximum_fl'=>nmsStoreInt($notam['maximumFl']??null),'effective_start'=>$effectiveStart,'effective_end'=>$effectiveEnd,'effective_end_raw'=>nmsStoreShortText($effectiveEndRaw,50),'estimated'=>nmsStoreShortText($notam['estimated']??null,20),'schedule'=>nmsStoreText($notam['schedule']??null),'lower_limit'=>nmsStoreText($notam['lowerLimit']??null),'upper_limit'=>nmsStoreText($notam['upperLimit']??null),'coordinates_raw'=>nmsStoreText($notam['coordinates']??null),'radius_nm'=>nmsStoreFloat($notam['radius']??null),'notam_text'=>nmsStoreText($notam['text']??null),'last_updated'=>nmsStoreDate($notam['lastUpdated']??null),'status'=>nmsRecordStatus($type,$effectiveStart,$effectiveEndRaw),'geometry_json'=>$geometryJson===false?null:$geometryJson,'raw_json'=>$rawJson,'source'=>'FAA_NMS','environment'=>$environment,
    ];
}

function nmsUpsertRecord(PDO $pdo, array $r): void {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) $stmt=$pdo->prepare('INSERT INTO notams (nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,account_id,selection_code,traffic,purpose,scope,minimum_fl,maximum_fl,effective_start,effective_end,effective_end_raw,estimated,schedule,lower_limit,upper_limit,coordinates_raw,radius_nm,notam_text,last_updated,status,geometry,raw_json,source,environment) VALUES (:nms_id,:series,:number,:year,:notam_type,:classification,:affected_fir,:location,:icao_location,:account_id,:selection_code,:traffic,:purpose,:scope,:minimum_fl,:maximum_fl,:effective_start,:effective_end,:effective_end_raw,:estimated,:schedule,:lower_limit,:upper_limit,:coordinates_raw,:radius_nm,:notam_text,:last_updated,:status,NULL,:raw_json,:source,:environment) ON DUPLICATE KEY UPDATE series=VALUES(series),number=VALUES(number),year=VALUES(year),notam_type=VALUES(notam_type),classification=VALUES(classification),affected_fir=VALUES(affected_fir),location=VALUES(location),icao_location=VALUES(icao_location),account_id=VALUES(account_id),selection_code=VALUES(selection_code),traffic=VALUES(traffic),purpose=VALUES(purpose),scope=VALUES(scope),minimum_fl=VALUES(minimum_fl),maximum_fl=VALUES(maximum_fl),effective_start=VALUES(effective_start),effective_end=VALUES(effective_end),effective_end_raw=VALUES(effective_end_raw),estimated=VALUES(estimated),schedule=VALUES(schedule),lower_limit=VALUES(lower_limit),upper_limit=VALUES(upper_limit),coordinates_raw=VALUES(coordinates_raw),radius_nm=VALUES(radius_nm),notam_text=VALUES(notam_text),last_updated=VALUES(last_updated),status=VALUES(status),geometry=VALUES(geometry),raw_json=VALUES(raw_json),source=VALUES(source),environment=VALUES(environment)');
    $geometryJson=$r['geometry_json']??null; unset($r['geometry_json']); $stmt->execute($r);
    if(is_string($geometryJson)&&$geometryJson!==''){try{$g=$pdo->prepare('UPDATE notams SET geometry=ST_GeomFromGeoJSON(:geometry_json) WHERE nms_id=:nms_id');$g->execute(['geometry_json'=>$geometryJson,'nms_id'=>$r['nms_id']]);}catch(Throwable){}}
}

function nmsApplyCancellationReference(PDO $pdo,array $record):?string{
    if(($record['status']??'')!=='cancelled')return null; $text=(string)($record['notam_text']??''); if(!preg_match('/\bNOTAMC\s+([A-Z])([0-9]{4})\/(\d{2})\b/i',$text,$m))return null;
    $series=strtoupper($m[1]);$serial=$m[2];$year2=(int)$m[3];$year4=2000+$year2;$target=$series.$serial.'/'.$m[3];
    $stmt=$pdo->prepare("UPDATE notams SET status='cancelled' WHERE source='FAA_NMS' AND environment=:environment AND series=:series AND (number=:serial OR number=:serial_slash OR number=:target) AND (year=:year4 OR year=:year2 OR year IS NULL)");
    $stmt->execute(['environment'=>(string)($record['environment']??''),'series'=>$series,'serial'=>$serial,'serial_slash'=>$serial.'/'.$m[3],'target'=>$target,'year4'=>$year4,'year2'=>$year2]); return $target;
}
function nmsEnsureSyncState(PDO $pdo,string $environment):void{$s=$pdo->prepare("INSERT INTO notam_sync_state (source,environment) VALUES ('FAA_NMS',:environment) ON DUPLICATE KEY UPDATE source=source");$s->execute(['environment'=>$environment]);}
function nmsSyncState(PDO $pdo,string $environment):array{nmsEnsureSyncState($pdo,$environment);$s=$pdo->prepare("SELECT source,environment,last_successful_sync,last_full_load,last_request_id,last_error,created_at,updated_at FROM notam_sync_state WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['environment'=>$environment]);return$s->fetch()?:[];}
function nmsStoreSyncError(PDO $pdo,string $environment,string $message):void{nmsEnsureSyncState($pdo,$environment);$s=$pdo->prepare("UPDATE notam_sync_state SET last_error=:error WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['error'=>function_exists('mb_substr')?mb_substr($message,0,65535):substr($message,0,65535),'environment'=>$environment]);}

function nmsCleanupOldNotams(PDO $pdo,string $environment,int $retentionDays=3,bool $force=false):array{
    $retentionDays=max(1,min(30,$retentionDays));$cutoff=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.$retentionDays.' days')->format('Y-m-d H:i:s');$marker=nmsCacheDir().DIRECTORY_SEPARATOR.'retention_'.preg_replace('/[^a-z0-9_-]+/i','_',$environment).'_'.$retentionDays.'d.json';
    if(!$force&&is_file($marker)){ $age=time()-(int)@filemtime($marker); if($age>=0&&$age<21600)return['ok'=>true,'skipped'=>true,'retentionDays'=>$retentionDays,'cutoff'=>$cutoff,'nextSweepInSeconds'=>21600-$age]; }
    $cancel=$pdo->prepare("SELECT notam_text FROM notams WHERE source='FAA_NMS' AND environment=:environment AND UPPER(COALESCE(notam_type,''))='C' AND COALESCE(effective_start,last_updated) IS NOT NULL AND COALESCE(effective_start,last_updated)<:cutoff");$cancel->execute(['environment'=>$environment,'cutoff'=>$cutoff]);$targets=[];
    while($row=$cancel->fetch()){if(preg_match('/\bNOTAMC\s+([A-Z])([0-9]{4})\/([0-9]{2})\b/i',(string)($row['notam_text']??''),$m))$targets[strtoupper($m[1]).$m[2].'/'.$m[3]]=['series'=>strtoupper($m[1]),'serial'=>$m[2],'year2'=>(int)$m[3],'year4'=>2000+(int)$m[3]];}
    $deletedCancelledTargets=$deletedExpired=$deletedCancellationMessages=0;
    try{$pdo->beginTransaction();if($targets){$d=$pdo->prepare("DELETE FROM notams WHERE source='FAA_NMS' AND environment=:environment AND series=:series AND (number=:serial OR number=:serial_slash OR number=:target) AND (year=:year4 OR year=:year2 OR year IS NULL)");foreach($targets as$target=>$parts){$d->execute(['environment'=>$environment,'series'=>$parts['series'],'serial'=>$parts['serial'],'serial_slash'=>$parts['serial'].'/'.str_pad((string)$parts['year2'],2,'0',STR_PAD_LEFT),'target'=>$target,'year4'=>$parts['year4'],'year2'=>$parts['year2']]);$deletedCancelledTargets+=$d->rowCount();}}
        do{$s=$pdo->prepare("DELETE FROM notams WHERE source='FAA_NMS' AND environment=:environment AND effective_end IS NOT NULL AND effective_end<:cutoff AND UPPER(COALESCE(effective_end_raw,''))<>'PERM' LIMIT 5000");$s->execute(['environment'=>$environment,'cutoff'=>$cutoff]);$n=$s->rowCount();$deletedExpired+=$n;}while($n===5000);
        do{$s=$pdo->prepare("DELETE FROM notams WHERE source='FAA_NMS' AND environment=:environment AND UPPER(COALESCE(notam_type,''))='C' AND COALESCE(effective_start,last_updated) IS NOT NULL AND COALESCE(effective_start,last_updated)<:cutoff LIMIT 5000");$s->execute(['environment'=>$environment,'cutoff'=>$cutoff]);$n=$s->rowCount();$deletedCancellationMessages+=$n;}while($n===5000);$pdo->commit();
    }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    $result=['ok'=>true,'skipped'=>false,'retentionDays'=>$retentionDays,'cutoff'=>$cutoff,'deletedExpired'=>$deletedExpired,'deletedCancelledTargets'=>$deletedCancelledTargets,'deletedCancellationMessages'=>$deletedCancellationMessages,'deletedTotal'=>$deletedExpired+$deletedCancelledTargets+$deletedCancellationMessages];@file_put_contents($marker,json_encode($result+['completedAt'=>gmdate('Y-m-d\TH:i:s\Z')],JSON_UNESCAPED_SLASHES),LOCK_EX);return$result;
}

function nmsRunDeltaSync(int $bootstrapLookbackSeconds=600):array{
    $cfg=nmsPrivateConfig();$environment=$cfg['env'];$pdo=nmsDb();$state=nmsSyncState($pdo,$environment);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$last=$state['last_successful_sync']??null;
    if($last){$cursor=new DateTimeImmutable((string)$last,new DateTimeZone('UTC'));$age=$now->getTimestamp()-$cursor->getTimestamp();if($environment==='production'&&$age<180)return['ok'=>false,'rateLimitedLocally'=>true,'environment'=>$environment,'lastSuccessfulSync'=>$last,'retryAfterSeconds'=>max(1,180-$age),'error'=>'Production delta sync is limited locally to one pull every 3 minutes.'];if($age>23*3600){$msg='Delta cursor is older than the safe NMS 24-hour window; a full recovery load is required.';nmsStoreSyncError($pdo,$environment,$msg);return['ok'=>false,'needsFullLoad'=>true,'environment'=>$environment,'lastSuccessfulSync'=>$last,'error'=>$msg];}$since=$cursor->modify('-30 seconds');}else{$bootstrapLookbackSeconds=max(60,min(3600,$bootstrapLookbackSeconds));$since=$now->modify('-'.$bootstrapLookbackSeconds.' seconds');}
    $sinceIso=$since->format('Y-m-d\TH:i:s\Z');$syncThrough=$now->format('Y-m-d H:i:s');$result=nmsGet('/notams',['lastUpdatedDate'=>$sinceIso],'GEOJSON');if(!($result['ok']??false)){$msg=(string)($result['error']??'FAA NMS delta request failed.');nmsStoreSyncError($pdo,$environment,$msg);return['ok'=>false,'environment'=>$environment,'since'=>$sinceIso,'upstreamStatus'=>$result['status']??null,'error'=>$msg];}
    $api=is_array($result['data']??null)?$result['data']:[];$features=$api['data']['geojson']??[];if(!is_array($features))$features=[];$processed=0;$skipped=0;$targets=[];
    try{$pdo->beginTransaction();foreach($features as$feature){if(!is_array($feature)){$skipped++;continue;}$record=nmsNormalizeFeature($feature,$environment);if($record===null){$skipped++;continue;}nmsUpsertRecord($pdo,$record);$target=nmsApplyCancellationReference($pdo,$record);if($target!==null)$targets[]=$target;$processed++;}$requestId=nmsStoreShortText($api['requestId']??$api['requestID']??$api['request_id']??null,150);$s=$pdo->prepare("UPDATE notam_sync_state SET last_successful_sync=:sync,last_request_id=:rid,last_error=NULL WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['sync'=>$syncThrough,'rid'=>$requestId,'environment'=>$environment]);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();nmsStoreSyncError($pdo,$environment,'Local NOTAM sync failed: '.$e->getMessage());return['ok'=>false,'environment'=>$environment,'since'=>$sinceIso,'error'=>'Local NOTAM sync failed.','detail'=>$environment==='staging'?$e->getMessage():null];}
    try{$cleanup=nmsCleanupOldNotams($pdo,$environment,3,false);}catch(Throwable$e){$cleanup=['ok'=>false,'retentionDays'=>3,'error'=>$e->getMessage()];}
    return['ok'=>true,'environment'=>$environment,'since'=>$sinceIso,'syncThrough'=>$syncThrough.'Z','upstreamStatus'=>$result['status']??200,'apiStatus'=>$api['status']??null,'received'=>count($features),'processed'=>$processed,'skipped'=>$skipped,'cancellationTargets'=>array_values(array_unique($targets)),'retentionCleanup'=>$cleanup];
}

function nmsFullContentApiPath(string $url):string{$url=trim($url);if($url==='')throw new RuntimeException('NMS initial load content URL is empty.');$path=preg_match('#^https?://#i',$url)?(string)parse_url($url,PHP_URL_PATH):$url;if(!str_starts_with($path,'/'))$path='/'.$path;if(str_starts_with($path,'/nmsapi/v1/'))return substr($path,strlen('/nmsapi/v1'));if(str_starts_with($path,'/v1/'))return substr($path,strlen('/v1'));if(str_starts_with($path,'/content/'))return$path;throw new RuntimeException('Unexpected NMS initial load content path.');}
function nmsFullWritePayload(string $payload,string $path):void{if(@file_put_contents($path,$payload,LOCK_EX)===false)throw new RuntimeException('NMS initial load temporary file could not be written.');}
function nmsFullMaterializeXml(string $inputPath):string{$fh=@fopen($inputPath,'rb');if(!$fh)throw new RuntimeException('NMS initial load temporary file could not be opened.');$magic=fread($fh,4);fclose($fh);$xmlPath=$inputPath.'.xml';if(substr($magic,0,2)==="\x1f\x8b"){if(!function_exists('gzopen'))throw new RuntimeException('PHP zlib extension is required for NMS gzip content.');$in=gzopen($inputPath,'rb');$out=fopen($xmlPath,'wb');if(!$in||!$out)throw new RuntimeException('NMS gzip file could not be opened.');while(!gzeof($in)){$chunk=gzread($in,1024*1024);if($chunk===false)break;fwrite($out,$chunk);}gzclose($in);fclose($out);return$xmlPath;}if($magic==="PK\x03\x04"){if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP ZipArchive extension is required for NMS zip content.');$zip=new ZipArchive();if($zip->open($inputPath)!==true)throw new RuntimeException('NMS zip file could not be opened.');$stream=null;for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);if($name!==false&&!str_ends_with($name,'/')){$stream=$zip->getStream($name);break;}}if(!$stream){$zip->close();throw new RuntimeException('NMS zip did not contain a readable file.');}$out=fopen($xmlPath,'wb');if(!$out){fclose($stream);$zip->close();throw new RuntimeException('NMS XML temporary file could not be created.');}stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);$zip->close();return$xmlPath;}if(!@copy($inputPath,$xmlPath))throw new RuntimeException('NMS initial load XML could not be materialized.');return$xmlPath;}

function nmsFullUpsert(PDO $pdo,array $r):void{static$stmt=null;if(!$stmt instanceof PDOStatement)$stmt=$pdo->prepare('INSERT INTO notams (nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,account_id,selection_code,traffic,purpose,scope,minimum_fl,maximum_fl,effective_start,effective_end,effective_end_raw,estimated,schedule,lower_limit,upper_limit,coordinates_raw,radius_nm,notam_text,last_updated,status,raw_json,source,environment) VALUES (:nms_id,:series,:number,:year,:notam_type,:classification,:affected_fir,:location,:icao_location,:account_id,:selection_code,:traffic,:purpose,:scope,:minimum_fl,:maximum_fl,:effective_start,:effective_end,:effective_end_raw,:estimated,:schedule,:lower_limit,:upper_limit,:coordinates_raw,:radius_nm,:notam_text,:last_updated,:status,NULL,:source,:environment) ON DUPLICATE KEY UPDATE series=COALESCE(VALUES(series),series),number=COALESCE(VALUES(number),number),year=COALESCE(VALUES(year),year),notam_type=COALESCE(VALUES(notam_type),notam_type),classification=COALESCE(VALUES(classification),classification),affected_fir=COALESCE(VALUES(affected_fir),affected_fir),location=COALESCE(VALUES(location),location),icao_location=COALESCE(VALUES(icao_location),icao_location),account_id=COALESCE(VALUES(account_id),account_id),selection_code=COALESCE(VALUES(selection_code),selection_code),traffic=COALESCE(VALUES(traffic),traffic),purpose=COALESCE(VALUES(purpose),purpose),scope=COALESCE(VALUES(scope),scope),minimum_fl=COALESCE(VALUES(minimum_fl),minimum_fl),maximum_fl=COALESCE(VALUES(maximum_fl),maximum_fl),effective_start=COALESCE(VALUES(effective_start),effective_start),effective_end=COALESCE(VALUES(effective_end),effective_end),effective_end_raw=COALESCE(VALUES(effective_end_raw),effective_end_raw),estimated=COALESCE(VALUES(estimated),estimated),schedule=COALESCE(VALUES(schedule),schedule),lower_limit=COALESCE(VALUES(lower_limit),lower_limit),upper_limit=COALESCE(VALUES(upper_limit),upper_limit),coordinates_raw=COALESCE(VALUES(coordinates_raw),coordinates_raw),radius_nm=COALESCE(VALUES(radius_nm),radius_nm),notam_text=COALESCE(VALUES(notam_text),notam_text),last_updated=COALESCE(VALUES(last_updated),last_updated),status=VALUES(status),source=VALUES(source),environment=VALUES(environment)');$stmt->execute($r);}
function nmsFullDate(mixed$value):?string{$raw=trim((string)($value??''));if($raw===''||strtoupper($raw)==='PERM')return null;try{$utc=new DateTimeZone('UTC');if(preg_match('/^\d{12}$/',$raw)){$d=DateTimeImmutable::createFromFormat('!YmdHi',$raw,$utc);return$d instanceof DateTimeImmutable?$d->format('Y-m-d H:i:s'):null;}if(preg_match('/^\d{14}$/',$raw)){$d=DateTimeImmutable::createFromFormat('!YmdHis',$raw,$utc);return$d instanceof DateTimeImmutable?$d->format('Y-m-d H:i:s'):null;}return(new DateTimeImmutable($raw))->setTimezone($utc)->format('Y-m-d H:i:s');}catch(Throwable){return null;}}
function nmsFullClass(?string$value):?string{$value=strtoupper(trim((string)$value));if($value==='')return null;return match($value){'DOM'=>'DOMESTIC','INTL'=>'INTERNATIONAL','MIL'=>'MILITARY','LMIL','LOCAL_MIL'=>'LOCAL_MILITARY',default=>$value};}
function nmsFullChildText(DOMNode$parent,string$localName):?string{foreach($parent->childNodes as$child)if($child instanceof DOMElement&&$child->localName===$localName){$v=trim($child->textContent);return$v===''?null:$v;}return null;}
function nmsFullFirstText(DOMXPath$xp,string$localName,?DOMNode$context=null):?string{$nodes=$xp->query('.//*[local-name()="'.$localName.'"][1]',$context);if(!$nodes||$nodes->length===0)return null;$v=trim((string)$nodes->item(0)?->textContent);return$v===''?null:$v;}
function nmsFullNormalizeMessage(string$xml,string$environment):?array{if(!class_exists(DOMDocument::class))throw new RuntimeException('PHP DOM extension is not enabled.');$wrapped='<nmswrap xmlns="http://www.aixm.aero/schema/5.1/message" xmlns:aixm="http://www.aixm.aero/schema/5.1" xmlns:event="http://www.aixm.aero/schema/5.1/event" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:fnse="http://www.aixm.aero/schema/5.1/extensions/FAA/FNSE" xmlns:fns="urn:us.gov.dot.faa.aim.fns">'.$xml.'</nmswrap>';$doc=new DOMDocument();$prev=libxml_use_internal_errors(true);$loaded=$doc->loadXML($wrapped,LIBXML_NONET|LIBXML_COMPACT|LIBXML_PARSEHUGE);libxml_clear_errors();libxml_use_internal_errors($prev);if(!$loaded||!$doc->documentElement)return null;$xp=new DOMXPath($doc);$messages=$xp->query('//*[local-name()="AIXMBasicMessage"]');if(!$messages||$messages->length===0)return null;$root=$messages->item(0);if(!$root instanceof DOMElement)return null;$nmsId=trim($root->getAttributeNS('http://www.opengis.net/gml/3.2','id'));if($nmsId==='')$nmsId=trim($root->getAttribute('gml:id'));if($nmsId==='')return null;$notams=$xp->query('.//*[local-name()="NOTAM"]',$root);if(!$notams||$notams->length===0)return null;$notam=$notams->item(0);if(!$notam instanceof DOMNode)return null;$extensions=$xp->query('.//*[local-name()="EventExtension"]',$root);$extension=($extensions&&$extensions->length>0)?$extensions->item(0):null;$startRaw=nmsFullChildText($notam,'effectiveStart');$endRaw=nmsFullChildText($notam,'effectiveEnd');$start=nmsFullDate($startRaw);$end=nmsFullDate($endRaw);$type=nmsFullChildText($notam,'type');$lastUpdated=$extension instanceof DOMNode?nmsFullFirstText($xp,'lastUpdated',$extension):null;$class=$extension instanceof DOMNode?nmsFullFirstText($xp,'classification',$extension):null;return['nms_id'=>$nmsId,'series'=>nmsFullChildText($notam,'series'),'number'=>nmsFullChildText($notam,'number'),'year'=>nmsStoreInt(nmsFullChildText($notam,'year')),'notam_type'=>$type,'classification'=>nmsFullClass($class),'affected_fir'=>nmsFullChildText($notam,'affectedFir'),'location'=>nmsFullChildText($notam,'location'),'icao_location'=>$extension instanceof DOMNode?nmsFullFirstText($xp,'icaoLocation',$extension):null,'account_id'=>$extension instanceof DOMNode?nmsFullFirstText($xp,'accountId',$extension):null,'selection_code'=>nmsFullChildText($notam,'selectionCode'),'traffic'=>nmsFullChildText($notam,'traffic'),'purpose'=>nmsFullChildText($notam,'purpose'),'scope'=>nmsFullChildText($notam,'scope'),'minimum_fl'=>nmsStoreInt(nmsFullChildText($notam,'minimumFl')),'maximum_fl'=>nmsStoreInt(nmsFullChildText($notam,'maximumFl')),'effective_start'=>$start,'effective_end'=>$end,'effective_end_raw'=>$endRaw,'estimated'=>nmsFullChildText($notam,'estimated'),'schedule'=>nmsFullChildText($notam,'schedule'),'lower_limit'=>nmsFullChildText($notam,'lowerLimit'),'upper_limit'=>nmsFullChildText($notam,'upperLimit'),'coordinates_raw'=>nmsFullChildText($notam,'coordinates'),'radius_nm'=>nmsStoreFloat(nmsFullChildText($notam,'radius')),'notam_text'=>nmsFullChildText($notam,'text'),'last_updated'=>nmsFullDate($lastUpdated),'status'=>nmsRecordStatus($type,$start,$endRaw),'source'=>'FAA_NMS','environment'=>$environment];}

function nmsFullProgressPath(string$environment):string{return nmsCacheDir().DIRECTORY_SEPARATOR.'initial_progress_'.$environment.'.json';}
function nmsFullReadProgress(string$environment):?array{$path=nmsFullProgressPath($environment);if(!is_file($path))return null;$raw=@file_get_contents($path);$data=$raw===false?null:json_decode($raw,true);if(!is_array($data)||empty($data['xmlPath'])||!is_file((string)$data['xmlPath']))return null;return$data;}
function nmsFullWriteProgress(string$environment,array$state):void{$json=json_encode($state,JSON_UNESCAPED_SLASHES);if($json===false||@file_put_contents(nmsFullProgressPath($environment),$json,LOCK_EX)===false)throw new RuntimeException('Initial load progress could not be saved.');}
function nmsFullExpectedFromXml(string$xmlPath):?int{$fh=@fopen($xmlPath,'rb');if(!$fh)return null;$head=fread($fh,262144);fclose($fh);return is_string($head)&&preg_match('/numberReturned=["\'](\d+)["\']/',$head,$m)?(int)$m[1]:null;}
function nmsFullFindReusableXml(string$environment):?string{$files=glob(nmsCacheDir().DIRECTORY_SEPARATOR.'initial_'.$environment.'_*.bin.xml')?:[];usort($files,fn(string$a,string$b):int=>(int)@filemtime($b)<=>(int)@filemtime($a));foreach($files as$file){$mtime=@filemtime($file);if($mtime!==false&&(time()-$mtime)<=21600&&is_file($file)&&filesize($file)>0)return$file;}return null;}
function nmsFullPrepareState(string$environment):array{$existing=nmsFullReadProgress($environment);if($existing!==null)return$existing;$xmlPath=nmsFullFindReusableXml($environment);$downloadPath=null;$source='reused-timeout-snapshot';if($xmlPath===null){$source='faa-initial-load';$meta=nmsGet('/notams/il',['allowRedirect'=>'false'],null);if(!($meta['ok']??false))throw new RuntimeException((string)($meta['error']??'FAA NMS initial load request failed.'));$base=nmsCacheDir().DIRECTORY_SEPARATOR.'initial_'.$environment.'_'.getmypid().'_'.time();$downloadPath=$base.'.bin';$metaData=is_array($meta['data']??null)?$meta['data']:[];$contentUrl=$metaData['data']['url']??null;if(is_string($contentUrl)&&trim($contentUrl)!==''){$download=nmsDownloadToFile(nmsFullContentApiPath($contentUrl),$downloadPath);if(!($download['ok']??false))throw new RuntimeException((string)($download['error']??'FAA NMS initial load content download failed.'));}else{$payload=$meta['body']??null;if(!is_string($payload)||$payload==='')throw new RuntimeException('FAA NMS initial load payload was empty.');nmsFullWritePayload($payload,$downloadPath);} $xmlPath=nmsFullMaterializeXml($downloadPath);} $state=['environment'=>$environment,'xmlPath'=>$xmlPath,'downloadPath'=>$downloadPath,'byteOffset'=>0,'processed'=>0,'skipped'=>0,'expected'=>nmsFullExpectedFromXml($xmlPath),'startedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'updatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>$source];nmsFullWriteProgress($environment,$state);return$state;}
function nmsFullNextMessage($fh,int$offset):?array{if(fseek($fh,$offset)!==0)throw new RuntimeException('Initial load cursor could not seek to the saved position.');$buffer='';$baseOffset=$offset;$closeTag=null;$found=false;while(!feof($fh)){$chunk=fread($fh,262144);if($chunk===false)throw new RuntimeException('Initial load snapshot read failed.');if($chunk==='')break;$buffer.=$chunk;if(!$found){if(preg_match('/<(?:(?<p>[A-Za-z_][A-Za-z0-9_.-]*):)?AIXMBasicMessage\b/',$buffer,$m,PREG_OFFSET_CAPTURE)){$start=(int)$m[0][1];$prefix=isset($m['p'][0])&&is_string($m['p'][0])?$m['p'][0]:'';$baseOffset+=$start;$buffer=substr($buffer,$start);$closeTag='</'.($prefix!==''?$prefix.':':'').'AIXMBasicMessage>';$found=true;}elseif(strlen($buffer)>512){$drop=strlen($buffer)-512;$baseOffset+=$drop;$buffer=substr($buffer,-512);continue;}}if($found&&$closeTag!==null){$endPos=strpos($buffer,$closeTag);if($endPos!==false){$end=$endPos+strlen($closeTag);return['xml'=>substr($buffer,0,$end),'nextOffset'=>$baseOffset+$end];}}if(strlen($buffer)>16777216)throw new RuntimeException('One initial load record exceeded the safe parser buffer.');}return null;}
function nmsFullCleanupState(string$environment,array$state):void{@unlink(nmsFullProgressPath($environment));foreach(['xmlPath','downloadPath']as$key){$path=(string)($state[$key]??'');if($path!==''&&is_file($path))@unlink($path);}}
function nmsRunFullLoadSlice(int$limit=250,int$maxSeconds=7):array{$cfg=nmsPrivateConfig();$environment=$cfg['env'];$pdo=nmsDb();$sync=nmsSyncState($pdo,$environment);$active=nmsFullReadProgress($environment);if($active===null&&!empty($sync['last_full_load'])){$last=strtotime((string)$sync['last_full_load'].' UTC');if($last!==false&&(time()-$last)<86400)return['ok'=>false,'complete'=>true,'rateLimitedLocally'=>true,'environment'=>$environment,'error'=>'Full load was already completed within the last 24 hours.','lastFullLoad'=>$sync['last_full_load']];}$limit=max(25,min(500,$limit));$maxSeconds=max(2,min(10,$maxSeconds));$started=microtime(true);try{$state=$active??nmsFullPrepareState($environment);$fh=@fopen((string)$state['xmlPath'],'rb');if(!$fh)throw new RuntimeException('Initial load snapshot could not be opened.');$offset=(int)($state['byteOffset']??0);$sliceProcessed=0;$sliceSkipped=0;$eof=false;$pdo->beginTransaction();while(($sliceProcessed+$sliceSkipped)<$limit){if((microtime(true)-$started)>=$maxSeconds)break;$next=nmsFullNextMessage($fh,$offset);if($next===null){$eof=true;break;}$offset=(int)$next['nextOffset'];$record=nmsFullNormalizeMessage((string)$next['xml'],$environment);if($record===null){$sliceSkipped++;continue;}nmsFullUpsert($pdo,$record);$sliceProcessed++;}if($pdo->inTransaction())$pdo->commit();fclose($fh);$clear=$pdo->prepare("UPDATE notam_sync_state SET last_error=NULL WHERE source='FAA_NMS' AND environment=:environment");$clear->execute(['environment'=>$environment]);$state['byteOffset']=$offset;$state['processed']=(int)($state['processed']??0)+$sliceProcessed;$state['skipped']=(int)($state['skipped']??0)+$sliceSkipped;$state['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');if(!$eof){nmsFullWriteProgress($environment,$state);$expected=isset($state['expected'])?(int)$state['expected']:null;$done=(int)$state['processed']+(int)$state['skipped'];return['ok'=>true,'complete'=>false,'environment'=>$environment,'processed'=>(int)$state['processed'],'skipped'=>(int)$state['skipped'],'sliceProcessed'=>$sliceProcessed,'sliceSkipped'=>$sliceSkipped,'expected'=>$expected,'progressPercent'=>$expected&&$expected>0?min(99.9,round(($done/$expected)*100,1)):null,'source'=>$state['source']??null];}$completed=gmdate('Y-m-d H:i:s');$s=$pdo->prepare("UPDATE notam_sync_state SET last_full_load=:completed,last_error=NULL WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['completed'=>$completed,'environment'=>$environment]);$fp=(int)$state['processed'];$fs=(int)$state['skipped'];$expected=isset($state['expected'])?(int)$state['expected']:null;nmsFullCleanupState($environment,$state);$catchup=nmsRunDeltaSync();return['ok'=>true,'complete'=>true,'environment'=>$environment,'processed'=>$fp,'skipped'=>$fs,'expected'=>$expected,'progressPercent'=>100,'completedAt'=>$completed.'Z','catchup'=>$catchup];}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();try{nmsStoreSyncError($pdo,$environment,'Full load slice failed: '.$e->getMessage());}catch(Throwable){}return['ok'=>false,'complete'=>false,'environment'=>$environment,'error'=>'FAA NMS full load slice failed.','detail'=>$e->getMessage()];}}

function nmsCronStatePath(string$environment):string{return nmsCacheDir().DIRECTORY_SEPARATOR.'cron_state_'.$environment.'.json';}
function nmsCronLogDir():string{$dir=dirname(__DIR__,3).'/logs/main/notam';if(!is_dir($dir))@mkdir($dir,0700,true);return$dir;}
function nmsCronCleanupLogs(int$days=3):void{$cutoff=time()-max(1,$days)*86400;foreach((array)glob(nmsCronLogDir().DIRECTORY_SEPARATOR.'nms-*.log')as$file){$mtime=@filemtime($file);if($mtime!==false&&$mtime<$cutoff)@unlink($file);}}
function nmsCronCleanupLegacyLog(int$days=3):void{$path=dirname(__DIR__,3).'/nms_cron.log';if(!is_file($path))return;$lines=@file($path,FILE_IGNORE_NEW_LINES);if(!is_array($lines)||!$lines)return;$cutoff=time()-max(1,$days)*86400;$keep=[];$unparsed=[];foreach($lines as$line){if(preg_match('/^\[([^\]]+)\]/',$line,$m)){$ts=strtotime((string)$m[1]);if($ts!==false&&$ts>=$cutoff)$keep[]=$line;}else$unparsed[]=$line;}if($unparsed)$keep=array_merge($keep,array_slice($unparsed,-200));@file_put_contents($path,$keep?implode(PHP_EOL,$keep).PHP_EOL:'',LOCK_EX);}
function nmsCronLog(string$message):void{static$cleaned=false;if(!$cleaned){nmsCronCleanupLogs(3);nmsCronCleanupLegacyLog(3);$cleaned=true;}$line='['.gmdate('Y-m-d\TH:i:s\Z').'] '.$message.PHP_EOL;@file_put_contents(nmsCronLogDir().DIRECTORY_SEPARATOR.'nms-'.gmdate('Y-m-d').'.log',$line,FILE_APPEND|LOCK_EX);fwrite(STDOUT,$line);fflush(STDOUT);}
function nmsCronWriteState(string$environment,array$state):void{$state['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');@file_put_contents(nmsCronStatePath($environment),json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);}
function nmsCronFullLoad(string$environment,int$maxRuntimeSeconds=900):array{$started=microtime(true);$rounds=0;$last=null;while((microtime(true)-$started)<$maxRuntimeSeconds){$rounds++;$last=nmsRunFullLoadSlice(500,10);if(!($last['ok']??false))return['rounds'=>$rounds]+$last;nmsCronWriteState($environment,['ok'=>true,'mode'=>'initial-load','running'=>!($last['complete']??false),'complete'=>(bool)($last['complete']??false),'processed'=>(int)($last['processed']??0),'skipped'=>(int)($last['skipped']??0),'expected'=>$last['expected']??null,'progressPercent'=>$last['progressPercent']??null,'rounds'=>$rounds]);if($last['complete']??false)return['rounds'=>$rounds]+$last;usleep(100000);}return['ok'=>true,'complete'=>false,'pausedForNextCron'=>true,'environment'=>$environment,'rounds'=>$rounds,'processed'=>(int)($last['processed']??0),'skipped'=>(int)($last['skipped']??0),'expected'=>$last['expected']??null,'progressPercent'=>$last['progressPercent']??null];}

$cfg=nmsPrivateConfig();$environment=$cfg['env'];$lockPath=nmsCacheDir().DIRECTORY_SEPARATOR.'cron_'.$environment.'.lock';$lock=@fopen($lockPath,'c+');if(!$lock){nmsCronLog('ERROR: NMS cron lock could not be opened.');exit(1);}if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);exit(0);}try{if($environment!=='production'){$result=['ok'=>false,'mode'=>'blocked','environment'=>$environment,'error'=>'Automatic NMS cron is enabled only for production.'];nmsCronWriteState($environment,$result);nmsCronLog('ERROR: '.json_encode($result,JSON_UNESCAPED_SLASHES));exit(2);}$pdo=nmsDb();$state=nmsSyncState($pdo,$environment);$countStmt=$pdo->prepare("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment=:environment");$countStmt->execute(['environment'=>$environment]);$notamCount=(int)$countStmt->fetchColumn();if($notamCount===0||empty($state['last_full_load'])){$existing=nmsFullReadProgress($environment);$resuming=is_array($existing);nmsCronWriteState($environment,['ok'=>true,'mode'=>$resuming?'initial-load':'initial-download','running'=>true,'complete'=>false,'processed'=>(int)($existing['processed']??0),'skipped'=>(int)($existing['skipped']??0),'expected'=>$existing['expected']??null,'progressPercent'=>null,'message'=>$resuming?'Existing FAA Initial Load snapshot is being resumed from saved progress.':'FAA Initial Load snapshot is being downloaded/prepared.']);nmsCronLog('Production baseline missing; Initial Load starting.');$result=nmsCronFullLoad($environment);nmsCronWriteState($environment,['ok'=>(bool)($result['ok']??false),'mode'=>'initial-load','running'=>!($result['complete']??false),'complete'=>(bool)($result['complete']??false),'processed'=>(int)($result['processed']??0),'skipped'=>(int)($result['skipped']??0),'expected'=>$result['expected']??null,'progressPercent'=>$result['progressPercent']??null,'pausedForNextCron'=>(bool)($result['pausedForNextCron']??false),'error'=>$result['detail']??$result['error']??null]);}else{nmsCronLog('Baseline present; Delta Sync starting.');$result=nmsRunDeltaSync();if(($result['needsFullLoad']??false)===true){$result=nmsCronFullLoad($environment);$mode='recovery-full-load';}else$mode='delta';nmsCronWriteState($environment,['ok'=>(bool)($result['ok']??false),'mode'=>$mode,'running'=>false,'processed'=>(int)($result['processed']??0),'received'=>isset($result['received'])?(int)$result['received']:null,'skipped'=>(int)($result['skipped']??0),'syncThrough'=>$result['syncThrough']??null,'retryAfterSeconds'=>$result['retryAfterSeconds']??null,'error'=>$result['error']??$result['detail']??null]);}if(($result['ok']??false)===true)$result['retentionCleanup']=nmsCleanupOldNotams($pdo,$environment,3,false);nmsCronLog('Result: '.json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));exit(($result['ok']??false)?0:1);}catch(Throwable$e){$result=['ok'=>false,'mode'=>'exception','environment'=>$environment,'error'=>$e->getMessage()];nmsCronWriteState($environment,$result);nmsCronLog('ERROR: '.json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
