<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/notam.php';
require_once __DIR__.'/runtime_storage.php';

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
function nmsValidatedDate(string $raw): ?string {
    try {
        $date=new DateTimeImmutable($raw,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if($errors!==false && ($errors['warning_count'] || $errors['error_count'])) return null;
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch(Throwable) { return null; }
}
function nmsStoreDate(mixed $value): ?string { $raw = nmsStoreText($value); if ($raw === null || strtoupper($raw) === 'PERM') return null; try { return nmsValidatedDate($raw); } catch (Throwable) { return null; } }
function nmsStoreInt(mixed $value): ?int { if ($value === null || $value === '') return null; if (is_numeric($value)) return (int)$value; $text = nmsStoreText($value); return $text !== null && preg_match('/-?\d+/', $text, $m) ? (int)$m[0] : null; }
function nmsStoreFloat(mixed $value): ?float { return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null; }
function nmsCanonicalClassification(mixed $value): ?string { $text = strtoupper(trim((string)($value ?? ''))); if ($text === '') return null; return match ($text) { 'DOM' => 'DOMESTIC', 'INTL' => 'INTERNATIONAL', 'MIL' => 'MILITARY', 'LMIL', 'LOCAL_MIL' => 'LOCAL_MILITARY', default => $text }; }
function nmsRecordStatus(?string $type, ?string $effectiveStart, ?string $effectiveEndRaw): string { if (strtoupper((string)$type) === 'C') return 'cancelled'; $now=time(); $start=$effectiveStart!==null?strtotime($effectiveStart.' UTC'):false; $endRaw=strtoupper(trim((string)$effectiveEndRaw)); $end=($endRaw!==''&&$endRaw!=='PERM')?strtotime((string)$effectiveEndRaw):false; if($start!==false&&$start>$now)return'inactive'; if($end!==false&&$end<$now)return'inactive'; if($start!==false||$endRaw==='PERM'||$end!==false)return'active'; return'unknown'; }

function nmsNormalizeFeature(array $feature, string $environment): ?array {
    $notam = $feature['properties']['coreNOTAMData']['notam'] ?? null;
    if (!is_array($notam)) return null;
    if (!is_string($notam['id'] ?? null)) return null;
    $nmsId = trim($notam['id']);
    if ($nmsId === '') return null;
    $effectiveStartRaw = nmsStoreText($notam['effectiveStart'] ?? null);
    $effectiveEndRaw = nmsStoreText($notam['effectiveEnd'] ?? null);
    $effectiveStart = nmsStoreDate($effectiveStartRaw);
    $effectiveEnd = nmsStoreDate($effectiveEndRaw);
    if (($effectiveStartRaw!==null && $effectiveStart===null) || ($effectiveEndRaw!==null && strtoupper($effectiveEndRaw)!=='PERM' && $effectiveEnd===null)) throw new RuntimeException('Invalid NMS effective date.');
    $type = nmsStoreShortText($notam['type'] ?? null, 10);
    $geometryJson = isset($feature['geometry']) ? json_encode($feature['geometry'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $rawJson = json_encode($feature, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rawJson === false || $geometryJson === false) throw new RuntimeException('Invalid NMS JSON record.');
    return [
        'nms_id'=>$nmsId,'series'=>nmsStoreShortText($notam['series']??null,10),'number'=>nmsStoreShortText($notam['number']??null,30),'year'=>nmsStoreInt($notam['year']??null),'notam_type'=>$type,'classification'=>nmsCanonicalClassification($notam['classification']??null),'affected_fir'=>nmsStoreShortText($notam['affectedFir']??null,20),'location'=>nmsStoreShortText($notam['location']??null,20),'icao_location'=>nmsStoreShortText($notam['icaoLocation']??null,20),'account_id'=>nmsStoreShortText($notam['accountId']??null,50),'selection_code'=>nmsStoreShortText($notam['selectionCode']??null,30),'traffic'=>nmsStoreShortText($notam['traffic']??null,20),'purpose'=>nmsStoreShortText($notam['purpose']??null,20),'scope'=>nmsStoreShortText($notam['scope']??null,20),'minimum_fl'=>nmsStoreInt($notam['minimumFl']??null),'maximum_fl'=>nmsStoreInt($notam['maximumFl']??null),'effective_start'=>$effectiveStart,'effective_end'=>$effectiveEnd,'effective_end_raw'=>nmsStoreShortText($effectiveEndRaw,50),'estimated'=>nmsStoreShortText($notam['estimated']??null,20),'schedule'=>nmsStoreText($notam['schedule']??null),'lower_limit'=>nmsStoreText($notam['lowerLimit']??null),'upper_limit'=>nmsStoreText($notam['upperLimit']??null),'coordinates_raw'=>nmsStoreText($notam['coordinates']??null),'radius_nm'=>nmsStoreFloat($notam['radius']??null),'notam_text'=>nmsStoreText($notam['text']??null),'last_updated'=>nmsStoreDate($notam['lastUpdated']??null),'status'=>nmsRecordStatus($type,$effectiveStart,$effectiveEndRaw),'geometry_json'=>$geometryJson===false?null:$geometryJson,'raw_json'=>$rawJson,'source'=>'FAA_NMS','environment'=>$environment,
    ];
}

function nmsGeoJsonParseFailure(Throwable $e): bool {
    return $e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[3037,3038,3039,3040,4048,4049],true);
}

function nmsDisplayIdent(array $r): string {
    $series=strtoupper(trim((string)($r['series'] ?? '')));
    $number=strtoupper(trim((string)($r['number'] ?? '')));
    $year=trim((string)($r['year'] ?? ''));
    if($number!=='' && $series!=='' && str_starts_with($number,$series))$ident=$number;else$ident=$series.$number;
    if($ident==='' )return(string)($r['nms_id'] ?? '');
    if($year!=='' && !preg_match('/\/\d{2}$/D',$ident))$ident.='/'.substr($year,-2);
    return $ident;
}

function nmsTrackedGeometryWarnings(string $environment, array $currentIssues=[]): array {
    $issues=[];
    $previous=ycReadNmsState($environment);
    if(is_array($previous)){
        $seed=(array)($previous['geometryDiagnostics']['trackedIssues']??$previous['geometryDiagnostics']['unresolved']??[]);
        foreach($seed as$item){
            if(!is_array($item))continue;
            $id=trim((string)($item['nmsId']??''));
            if($id==='')continue;
            if(!in_array($item['resolution']??'',['notam-stored-geometry-preserved-or-null','notam-stored-current-geometry-unavailable'],true))continue;
            $issues[$id]=$item;
        }
    }
    foreach($currentIssues as$item){
        if(!is_array($item))continue;
        $id=trim((string)($item['nmsId']??''));
        if($id==='')continue;
        if(!in_array($item['resolution']??'',['notam-stored-geometry-preserved-or-null','notam-stored-current-geometry-unavailable'],true))continue;
        $issues[$id]=$item;
    }
    return $issues;
}
function nmsTextFallbackKind(array $row): ?array {
    $explicit=explicitGeometry($row);
    if($explicit!==null&&normalizeGeometry($explicit['geometry'])!==null)return ['kind'=>$explicit['source']];
    if(preg_match('/\bSEE\s+FDC\s+([0-9]\/\d{4})\b/i',(string)($row['notam_text']??''),$m))return ['kind'=>'referenced-notam','reference'=>$m[1]];
    return null;
}

function nmsGeometryDiagnostics(PDO $pdo,string $environment,array $currentIssues=[]): array {
    $logged=nmsTrackedGeometryWarnings($environment,$currentIssues);
    $stats=['unresolvedCount'=>0,'unresolved'=>[],'resolvedByFallback'=>0,'resolvedFallbackItems'=>[],'resolvedByReference'=>0,'resolvedReferenceItems'=>[],'resolvedUpstream'=>0,'notApplicable'=>0,'removed'=>0,'lastWarningAt'=>null,'trackedIssues'=>[]];
    if(!$logged)return$stats;
    foreach($logged as$item){$t=(string)($item['time']??'');if($t!==''&&($stats['lastWarningAt']===null||strcmp($t,(string)$stats['lastWarningAt'])>0))$stats['lastWarningAt']=$t;}
    $ids=array_keys($logged);$rows=[];
    foreach(array_chunk($ids,200)as$chunk){$holders=implode(',',array_fill(0,count($chunk),'?'));$q=$pdo->prepare("SELECT nms_id,series,number,year,notam_type,location,icao_location,selection_code,notam_text,raw_json,geometry IS NOT NULL has_geometry FROM notams WHERE source='FAA_NMS' AND environment=? AND nms_id IN ($holders)");$q->execute([$environment,...$chunk]);while($r=$q->fetch())$rows[(string)$r['nms_id']]=$r;}
    $clearStale=$pdo->prepare("UPDATE notams SET geometry=NULL WHERE source='FAA_NMS' AND environment=:environment AND nms_id=:id");
    foreach($logged as$id=>$issue){
        $row=$rows[$id]??null;if(!is_array($row)){$stats['removed']++;continue;}
        if(strtoupper(trim((string)($row['notam_type']??'')))==='C'){$stats['notApplicable']++;continue;}
        if((int)($row['has_geometry']??0)===1){$raw=json_decode((string)($row['raw_json']??''),true);$current=$raw['geometry']??null;if(is_array($current)&&validGeoJson($current)){$stats['resolvedUpstream']++;continue;}$clearStale->execute(['environment'=>$environment,'id'=>$id]);}
        $entry=['time'=>$issue['time']??null,'nmsId'=>$id,'ident'=>nmsDisplayIdent($row),'location'=>($row['icao_location']??null)?:($row['location']??null),'geometryType'=>$issue['geometryType']??null,'error'=>$issue['error']??'Geometry unavailable.'];
        $fallback=nmsTextFallbackKind($row);
        if(is_array($fallback)){
            if(($fallback['kind']??'')==='referenced-notam'){
                $entry['reference']=$fallback['reference']??null;
                if(referencedFdcGeometry($pdo,$row,$environment,new DateTimeImmutable('now',new DateTimeZone('UTC')))!==null){$entry['fallback']='referenced-notam';$stats['resolvedByReference']++;$stats['resolvedReferenceItems'][]=$entry;$stats['trackedIssues'][]=$issue;continue;}
            }else{$entry['fallback']=$fallback['kind']??'text';$stats['resolvedByFallback']++;$stats['resolvedFallbackItems'][]=$entry;$stats['trackedIssues'][]=$issue;continue;}
        }
        $stats['unresolved'][]=$entry;$stats['trackedIssues'][]=$issue;
    }
    usort($stats['unresolved'],fn(array$a,array$b):int=>(string)($b['time']??'')<=>(string)($a['time']??''));
    $stats['unresolvedCount']=count($stats['unresolved']);
    return$stats;
}
function nmsGeometryIssue(array $r, string $geometryJson, Throwable $e, string $resolution): array {
    $decoded = json_decode($geometryJson, true);
    $type = is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
    $ident = nmsDisplayIdent($r);
    $summary = [
        'time'=>gmdate('Y-m-d\TH:i:s\Z'),
        'nmsId'=>(string)($r['nms_id'] ?? ''),
        'ident'=>$ident !== '' ? $ident : (string)($r['nms_id'] ?? ''),
        'fir'=>$r['affected_fir'] ?? null,
        'location'=>($r['icao_location'] ?? null) ?: ($r['location'] ?? null),
        'geometryType'=>$type,
        'resolution'=>$resolution,
        'error'=>$e->getMessage(),
    ];
    $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    nmsCronLog('WARNING geometry: ' . ($encoded === false ? $summary['error'] : $encoded));
    return $summary;
}

function nmsUpsertRecord(PDO $pdo, array $r): ?array {
    static $stmt = null;
    if (!$stmt instanceof PDOStatement) $stmt=$pdo->prepare('INSERT INTO notams (nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,account_id,selection_code,traffic,purpose,scope,minimum_fl,maximum_fl,effective_start,effective_end,effective_end_raw,estimated,schedule,lower_limit,upper_limit,coordinates_raw,radius_nm,notam_text,last_updated,status,geometry,raw_json,source,environment) VALUES (:nms_id,:series,:number,:year,:notam_type,:classification,:affected_fir,:location,:icao_location,:account_id,:selection_code,:traffic,:purpose,:scope,:minimum_fl,:maximum_fl,:effective_start,:effective_end,:effective_end_raw,:estimated,:schedule,:lower_limit,:upper_limit,:coordinates_raw,:radius_nm,:notam_text,:last_updated,:status,NULL,:raw_json,:source,:environment) ON DUPLICATE KEY UPDATE geometry=NULL,series=VALUES(series),number=VALUES(number),year=VALUES(year),notam_type=VALUES(notam_type),classification=VALUES(classification),affected_fir=VALUES(affected_fir),location=VALUES(location),icao_location=VALUES(icao_location),account_id=VALUES(account_id),selection_code=VALUES(selection_code),traffic=VALUES(traffic),purpose=VALUES(purpose),scope=VALUES(scope),minimum_fl=VALUES(minimum_fl),maximum_fl=VALUES(maximum_fl),effective_start=VALUES(effective_start),effective_end=VALUES(effective_end),effective_end_raw=VALUES(effective_end_raw),estimated=VALUES(estimated),schedule=VALUES(schedule),lower_limit=VALUES(lower_limit),upper_limit=VALUES(upper_limit),coordinates_raw=VALUES(coordinates_raw),radius_nm=VALUES(radius_nm),notam_text=VALUES(notam_text),last_updated=VALUES(last_updated),status=VALUES(status),raw_json=VALUES(raw_json),source=VALUES(source),environment=VALUES(environment)');
    $geometryJson=$r['geometry_json']??null;
    unset($r['geometry_json']);
    $stmt->execute($r);
    if(!is_string($geometryJson)||$geometryJson==='') return null;

    $decodedGeometry=json_decode($geometryJson,true);
    if(strtoupper((string)($r['notam_type']??''))==='C')return null;
    if(!is_array($decodedGeometry)||!validGeoJson($decodedGeometry))return nmsGeometryIssue($r,$geometryJson,new UnexpectedValueException('Invalid current FAA geometry.'),'notam-stored-current-geometry-unavailable');
    $params=['geometry_json'=>$geometryJson,'nms_id'=>$r['nms_id'],'source'=>$r['source'],'environment'=>$r['environment']];
    try {
        $g=$pdo->prepare('UPDATE notams SET geometry=ST_GeomFromGeoJSON(:geometry_json) WHERE nms_id=:nms_id AND source=:source AND environment=:environment');
        $g->execute($params);
        return null;
    } catch(Throwable $first) {
        if(!nmsGeoJsonParseFailure($first)) throw $first;
        try {
            $g2=$pdo->prepare('UPDATE notams SET geometry=ST_GeomFromGeoJSON(:geometry_json,2) WHERE nms_id=:nms_id AND source=:source AND environment=:environment');
            $g2->execute($params);
            nmsCronLog('RECOVERED geometry 2D: '.nmsDisplayIdent($r).' '.(string)($r['nms_id']??''));
            return null;
        } catch(Throwable $second) {
            if(!nmsGeoJsonParseFailure($second)) throw $second;
            return nmsGeometryIssue($r,$geometryJson,$second,'notam-stored-current-geometry-unavailable');
        }
    }
}

function nmsCancellationTarget(PDO $pdo, array $record): ?array {
    if (strtoupper((string)($record['notam_type']??''))!=='C') return null;
    $resolved=resolveNotamReferences($pdo,[$record],(string)($record['environment']??''),new DateTimeImmutable('now',new DateTimeZone('UTC')));
    return $resolved['byEvent'][(string)($record['nms_id']??'')]??null;
}
function nmsApplyCancellationReferences(PDO $pdo,array $records,string $environment,DateTimeImmutable $at): array {
    $resolved=resolveNotamReferences($pdo,$records,$environment,$at);
    foreach (array_chunk(array_keys($resolved['cancellation']),100) as $ids) {
        $stmt=$pdo->prepare("UPDATE notams SET status='cancelled' WHERE source='FAA_NMS' AND environment=? AND nms_id IN (".implode(',',array_fill(0,count($ids),'?')).')');
        $stmt->execute([$environment,...$ids]);
    }
    return $resolved;
}
function nmsEnsureSyncState(PDO $pdo,string $environment):void{$s=$pdo->prepare("INSERT INTO notam_sync_state (source,environment) VALUES ('FAA_NMS',:environment) ON DUPLICATE KEY UPDATE source=source");$s->execute(['environment'=>$environment]);}
function nmsSyncState(PDO $pdo,string $environment):array{nmsEnsureSyncState($pdo,$environment);$s=$pdo->prepare("SELECT source,environment,last_successful_sync,last_full_load,last_request_id,last_error,created_at,updated_at FROM notam_sync_state WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['environment'=>$environment]);return$s->fetch()?:[];}
function nmsStoreSyncError(PDO $pdo,string $environment,string $message):void{nmsEnsureSyncState($pdo,$environment);$s=$pdo->prepare("UPDATE notam_sync_state SET last_error=:error WHERE source='FAA_NMS' AND environment=:environment");$s->execute(['error'=>function_exists('mb_substr')?mb_substr($message,0,65535):substr($message,0,65535),'environment'=>$environment]);}

function nmsCleanupOldNotams(PDO $pdo,string $environment,int $retentionDays=3,bool $force=false):array {
    $retentionDays=max(1,min(30,$retentionDays));
    $cutoff=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.$retentionDays.' days')->format('Y-m-d H:i:s');
    $marker=nmsCacheDir().DIRECTORY_SEPARATOR.'retention_'.preg_replace('/[^a-z0-9_-]+/i','_',$environment).'_'.$retentionDays.'d.json';
    if(!$force&&is_file($marker)){
        $age=time()-(int)@filemtime($marker);
        if($age>=0&&$age<21600)return['ok'=>true,'skipped'=>true,'retentionDays'=>$retentionDays,'cutoff'=>$cutoff,'nextSweepInSeconds'=>21600-$age];
    }
    $previous=is_file($marker)?json_decode((string)@file_get_contents($marker),true):null;
    $cancellationCursor=is_array($previous)?(string)($previous['cancellationCursor']??''):'';
    $deletedCancelledTargets=$deletedExpired=$deletedCancellationMessages=$unresolved=0;
    try {
        $pdo->beginTransaction();
        $cancel=$pdo->prepare("SELECT nms_id,series,number,year,raw_json,notam_type,notam_text,account_id,affected_fir,location,icao_location,effective_start,last_updated,environment FROM notams WHERE source='FAA_NMS' AND environment=:environment AND UPPER(COALESCE(notam_type,''))='C' AND COALESCE(effective_start,last_updated)<:cutoff AND nms_id>:cursor ORDER BY nms_id LIMIT 250");
        $cancel->execute(['environment'=>$environment,'cutoff'=>$cutoff,'cursor'=>$cancellationCursor]);
        $messages=$cancel->fetchAll();
        $cancellationCursor=count($messages)===250?(string)$messages[array_key_last($messages)]['nms_id']:'';
        $references=resolveNotamReferences($pdo,$messages,$environment,new DateTimeImmutable('now',new DateTimeZone('UTC')));
        $delete=$pdo->prepare("DELETE FROM notams WHERE source='FAA_NMS' AND environment=:environment AND nms_id=:id");
        foreach($messages as $record) {
            $target=$references['byEvent'][(string)$record['nms_id']]??null;
            if ($target===null) { $unresolved++; continue; }
            $delete->execute(['environment'=>$environment,'id'=>$target['id']]);
            $deletedCancelledTargets+=$delete->rowCount();
            $delete->execute(['environment'=>$environment,'id'=>$record['nms_id']]);
            $deletedCancellationMessages+=$delete->rowCount();
        }
        do {
            $stmt=$pdo->prepare("DELETE FROM notams WHERE source='FAA_NMS' AND environment=:environment AND effective_end IS NOT NULL AND effective_end<:cutoff AND UPPER(COALESCE(effective_end_raw,''))<>'PERM' AND UPPER(COALESCE(notam_type,''))<>'C' LIMIT 5000");
            $stmt->execute(['environment'=>$environment,'cutoff'=>$cutoff]);
            $n=$stmt->rowCount();$deletedExpired+=$n;
        } while($n===5000);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
    $result=['ok'=>true,'skipped'=>false,'retentionDays'=>$retentionDays,'cutoff'=>$cutoff,'deletedExpired'=>$deletedExpired,'deletedCancelledTargets'=>$deletedCancelledTargets,'deletedCancellationMessages'=>$deletedCancellationMessages,'unresolvedCancellationMessages'=>$unresolved,'referenceResolution'=>$references['diagnostics'],'cancellationCursor'=>$cancellationCursor,'deletedTotal'=>$deletedExpired+$deletedCancelledTargets+$deletedCancellationMessages];
    @file_put_contents($marker,json_encode($result+['completedAt'=>gmdate('Y-m-d\TH:i:s\Z')],JSON_UNESCAPED_SLASHES),LOCK_EX);
    return $result;
}

function nmsRunDeltaSync(int $bootstrapLookbackSeconds=600, ?string $recoverySince=null):array{
    $cfg=nmsPrivateConfig();$environment=$cfg['env'];$pdo=nmsDb();$state=nmsSyncState($pdo,$environment);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$last=$state['last_successful_sync']??null;
    if($recoverySince!==null){$since=new DateTimeImmutable($recoverySince,new DateTimeZone('UTC'));$age=$now->getTimestamp()-$since->getTimestamp();if($age<0||$age>23*3600)throw new RuntimeException('Recovery snapshot is outside the safe delta window.');}
    elseif($last){$cursor=new DateTimeImmutable((string)$last,new DateTimeZone('UTC'));$age=$now->getTimestamp()-$cursor->getTimestamp();if($environment==='production'&&$age<180)return['ok'=>false,'rateLimitedLocally'=>true,'environment'=>$environment,'lastSuccessfulSync'=>$last,'retryAfterSeconds'=>max(1,180-$age),'error'=>'Production delta sync is limited locally to one pull every 3 minutes.'];if($age>23*3600){$msg='Delta cursor is older than the safe NMS 24-hour window; a full recovery load is required.';nmsStoreSyncError($pdo,$environment,$msg);return['ok'=>false,'needsFullLoad'=>true,'environment'=>$environment,'lastSuccessfulSync'=>$last,'error'=>$msg];}$since=$cursor->modify('-30 seconds');}else{$bootstrapLookbackSeconds=max(60,min(3600,$bootstrapLookbackSeconds));$since=$now->modify('-'.$bootstrapLookbackSeconds.' seconds');}
    $sinceIso=$since->format('Y-m-d\TH:i:s\Z');$syncThrough=$now->format('Y-m-d H:i:s');$result=nmsGet('/notams',['lastUpdatedDate'=>$sinceIso],'GEOJSON');if(!($result['ok']??false)){$msg=(string)($result['error']??'FAA NMS delta request failed.');nmsStoreSyncError($pdo,$environment,$msg);return['ok'=>false,'environment'=>$environment,'since'=>$sinceIso,'upstreamStatus'=>$result['status']??null,'error'=>$msg];}
    $api=$result['data']??null;
    if (!is_array($api) || (isset($api['status']) && (!is_scalar($api['status']) || preg_match('/error|fail|partial|^[45][0-9]{2}$/i',(string)$api['status']))) || !empty($api['errors']) || !empty($api['error']) || !empty($api['data']['hasMore']) || !empty($api['data']['nextPage']) || !is_array($api['data']??null) || !array_key_exists('geojson',$api['data']) || !is_array($api['data']['geojson']) || !array_is_list($api['data']['geojson'])) {
        nmsStoreSyncError($pdo,$environment,'NMS delta response schema invalid; cursor was not advanced.');
        return ['ok'=>false,'environment'=>$environment,'since'=>$sinceIso,'error'=>'Invalid NMS delta response.'];
    }
    $features=$api['data']['geojson'];$processed=0;$skipped=0;$cancellations=[];$geometryWarnings=0;$geometryIssues=[];$rawArchiveFailures=0;
    try {
        $pdo->beginTransaction();
        foreach ($features as $feature) {
            if (!is_array($feature)) throw new RuntimeException('Invalid NMS delta feature.');
            if(!ycArchiveRawNmsValue($feature))$rawArchiveFailures++;
            $record=nmsNormalizeFeature($feature,$environment);
            if ($record===null) throw new RuntimeException('Invalid NMS delta record.');
            $geometryIssue=nmsUpsertRecord($pdo,$record);
            if (is_array($geometryIssue)) {
                $geometryWarnings++;
                if (count($geometryIssues)<500) $geometryIssues[]=$geometryIssue;
            }
            $id=(string)$record['nms_id'];
            if (strtoupper((string)$record['notam_type'])==='C') $cancellations[$id]=$record;
            else unset($cancellations[$id]);
            $processed++;
        }
        $references=nmsApplyCancellationReferences($pdo,array_values($cancellations),$environment,$now);
        $requestId=nmsStoreShortText($api['requestId']??$api['requestID']??$api['request_id']??null,150);
        $s=$pdo->prepare("UPDATE notam_sync_state SET last_successful_sync=:sync,last_request_id=:rid,last_error=NULL WHERE source='FAA_NMS' AND environment=:environment");
        $s->execute(['sync'=>$syncThrough,'rid'=>$requestId,'environment'=>$environment]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        nmsStoreSyncError($pdo,$environment,'Local NOTAM sync failed: '.$e->getMessage());
        return ['ok'=>false,'environment'=>$environment,'since'=>$sinceIso,'error'=>'Local NOTAM sync failed.','detail'=>$environment==='staging'?$e->getMessage():null,'rawArchiveFailures'=>$rawArchiveFailures];
    }
    if($rawArchiveFailures>0)nmsCronLog('WARNING raw NMS archive write failures: '.$rawArchiveFailures);
    try{$cleanup=nmsCleanupOldNotams($pdo,$environment,3,false);}catch(Throwable$e){$cleanup=['ok'=>false,'retentionDays'=>3,'error'=>$e->getMessage()];}
    try{$geometryDiagnostics=nmsGeometryDiagnostics($pdo,$environment,$geometryIssues);}catch(Throwable$e){$geometryDiagnostics=['unresolvedCount'=>$geometryWarnings,'unresolved'=>$geometryIssues,'error'=>'Geometry diagnostics failed.'];nmsCronLog('WARNING geometry diagnostics: '.$e->getMessage());}
    return['ok'=>true,'environment'=>$environment,'since'=>$sinceIso,'syncThrough'=>$syncThrough.'Z','upstreamStatus'=>$result['status']??200,'apiStatus'=>$api['status']??null,'received'=>count($features),'processed'=>$processed,'skipped'=>$skipped,'geometryWarnings'=>$geometryWarnings,'geometryIssues'=>$geometryIssues,'geometryDiagnostics'=>$geometryDiagnostics,'rawArchiveFailures'=>$rawArchiveFailures,'cancellationTargets'=>array_values(array_unique(array_column($references['byEvent'],'ident'))),'referenceResolution'=>$references['diagnostics'],'retentionCleanup'=>$cleanup];
}

function nmsFullContentApiPath(string $url):string{$url=trim($url);if($url==='')throw new RuntimeException('NMS initial load content URL is empty.');$path=preg_match('#^https?://#i',$url)?(string)parse_url($url,PHP_URL_PATH):$url;if(!str_starts_with($path,'/'))$path='/'.$path;if(str_starts_with($path,'/nmsapi/v1/'))return substr($path,strlen('/nmsapi/v1'));if(str_starts_with($path,'/v1/'))return substr($path,strlen('/v1'));if(str_starts_with($path,'/content/'))return$path;throw new RuntimeException('Unexpected NMS initial load content path.');}
function nmsFullWritePayload(string $payload,string $path):void{if(@file_put_contents($path,$payload,LOCK_EX)===false)throw new RuntimeException('NMS initial load temporary file could not be written.');}
function nmsFullMaterializeXml(string $inputPath):string{$fh=@fopen($inputPath,'rb');if(!$fh)throw new RuntimeException('NMS initial load temporary file could not be opened.');$magic=fread($fh,4);fclose($fh);$xmlPath=$inputPath.'.xml';if(substr($magic,0,2)==="\x1f\x8b"){if(!function_exists('gzopen'))throw new RuntimeException('PHP zlib extension is required for NMS gzip content.');$in=gzopen($inputPath,'rb');$out=fopen($xmlPath,'wb');if(!$in||!$out)throw new RuntimeException('NMS gzip file could not be opened.');while(!gzeof($in)){$chunk=gzread($in,1024*1024);if($chunk===false)break;fwrite($out,$chunk);}gzclose($in);fclose($out);return$xmlPath;}if($magic==="PK\x03\x04"){if(!class_exists(ZipArchive::class))throw new RuntimeException('PHP ZipArchive extension is required for NMS zip content.');$zip=new ZipArchive();if($zip->open($inputPath)!==true)throw new RuntimeException('NMS zip file could not be opened.');$stream=null;for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);if($name!==false&&!str_ends_with($name,'/')){$stream=$zip->getStream($name);break;}}if(!$stream){$zip->close();throw new RuntimeException('NMS zip did not contain a readable file.');}$out=fopen($xmlPath,'wb');if(!$out){fclose($stream);$zip->close();throw new RuntimeException('NMS XML temporary file could not be created.');}stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);$zip->close();return$xmlPath;}if(!@copy($inputPath,$xmlPath))throw new RuntimeException('NMS initial load XML could not be materialized.');return$xmlPath;}

function nmsFullUpsert(PDO $pdo,array $r):void{static$stmt=null;if(!$stmt instanceof PDOStatement)$stmt=$pdo->prepare('INSERT INTO notams (nms_id,series,number,year,notam_type,classification,affected_fir,location,icao_location,account_id,selection_code,traffic,purpose,scope,minimum_fl,maximum_fl,effective_start,effective_end,effective_end_raw,estimated,schedule,lower_limit,upper_limit,coordinates_raw,radius_nm,notam_text,last_updated,status,raw_json,source,environment) VALUES (:nms_id,:series,:number,:year,:notam_type,:classification,:affected_fir,:location,:icao_location,:account_id,:selection_code,:traffic,:purpose,:scope,:minimum_fl,:maximum_fl,:effective_start,:effective_end,:effective_end_raw,:estimated,:schedule,:lower_limit,:upper_limit,:coordinates_raw,:radius_nm,:notam_text,:last_updated,:status,:raw_json,:source,:environment) ON DUPLICATE KEY UPDATE geometry=IF((notam_type<=>VALUES(notam_type)) AND (location<=>VALUES(location)) AND (icao_location<=>VALUES(icao_location)) AND (selection_code<=>VALUES(selection_code)) AND (notam_text<=>VALUES(notam_text)) AND (coordinates_raw<=>VALUES(coordinates_raw)) AND (radius_nm<=>VALUES(radius_nm)) AND (effective_start<=>VALUES(effective_start)) AND (effective_end_raw<=>VALUES(effective_end_raw)) AND (last_updated<=>VALUES(last_updated)),geometry,NULL),raw_json=IF(geometry IS NOT NULL,raw_json,VALUES(raw_json)),series=COALESCE(VALUES(series),series),number=COALESCE(VALUES(number),number),year=COALESCE(VALUES(year),year),notam_type=COALESCE(VALUES(notam_type),notam_type),classification=COALESCE(VALUES(classification),classification),affected_fir=COALESCE(VALUES(affected_fir),affected_fir),location=COALESCE(VALUES(location),location),icao_location=COALESCE(VALUES(icao_location),icao_location),account_id=COALESCE(VALUES(account_id),account_id),selection_code=COALESCE(VALUES(selection_code),selection_code),traffic=COALESCE(VALUES(traffic),traffic),purpose=COALESCE(VALUES(purpose),purpose),scope=COALESCE(VALUES(scope),scope),minimum_fl=COALESCE(VALUES(minimum_fl),minimum_fl),maximum_fl=COALESCE(VALUES(maximum_fl),maximum_fl),effective_start=COALESCE(VALUES(effective_start),effective_start),effective_end=COALESCE(VALUES(effective_end),effective_end),effective_end_raw=COALESCE(VALUES(effective_end_raw),effective_end_raw),estimated=COALESCE(VALUES(estimated),estimated),schedule=COALESCE(VALUES(schedule),schedule),lower_limit=COALESCE(VALUES(lower_limit),lower_limit),upper_limit=COALESCE(VALUES(upper_limit),upper_limit),coordinates_raw=COALESCE(VALUES(coordinates_raw),coordinates_raw),radius_nm=COALESCE(VALUES(radius_nm),radius_nm),notam_text=COALESCE(VALUES(notam_text),notam_text),last_updated=COALESCE(VALUES(last_updated),last_updated),status=VALUES(status),source=VALUES(source),environment=VALUES(environment)');$stmt->execute($r);}
function nmsFullDate(mixed$value):?string{$raw=trim((string)($value??''));if($raw===''||strtoupper($raw)==='PERM')return null;try{$utc=new DateTimeZone('UTC');if(preg_match('/^\d{12}$/',$raw)){$d=DateTimeImmutable::createFromFormat('!YmdHi',$raw,$utc);return$d instanceof DateTimeImmutable && DateTimeImmutable::getLastErrors()===false?$d->format('Y-m-d H:i:s'):null;}if(preg_match('/^\d{14}$/',$raw)){$d=DateTimeImmutable::createFromFormat('!YmdHis',$raw,$utc);return$d instanceof DateTimeImmutable && DateTimeImmutable::getLastErrors()===false?$d->format('Y-m-d H:i:s'):null;}return nmsValidatedDate($raw);}catch(Throwable){return null;}}
function nmsFullClass(?string$value):?string{$value=strtoupper(trim((string)$value));if($value==='')return null;return match($value){'DOM'=>'DOMESTIC','INTL'=>'INTERNATIONAL','MIL'=>'MILITARY','LMIL','LOCAL_MIL'=>'LOCAL_MILITARY',default=>$value};}
function nmsFullChildText(DOMNode$parent,string$localName):?string{foreach($parent->childNodes as$child)if($child instanceof DOMElement&&$child->localName===$localName){$v=trim($child->textContent);return$v===''?null:$v;}return null;}
function nmsFullFirstText(DOMXPath$xp,string$localName,?DOMNode$context=null):?string{$nodes=$xp->query('.//*[local-name()="'.$localName.'"][1]',$context);if(!$nodes||$nodes->length===0)return null;$v=trim((string)$nodes->item(0)?->textContent);return$v===''?null:$v;}
function nmsFullNormalizeMessage(string$xml,string$environment):?array{if(stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)throw new RuntimeException('Unexpected XML declaration.');if(!class_exists(DOMDocument::class))throw new RuntimeException('PHP DOM extension is not enabled.');$wrapped='<nmswrap xmlns="http://www.aixm.aero/schema/5.1/message" xmlns:aixm="http://www.aixm.aero/schema/5.1" xmlns:event="http://www.aixm.aero/schema/5.1/event" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:fnse="http://www.aixm.aero/schema/5.1/extensions/FAA/FNSE" xmlns:fns="urn:us.gov.dot.faa.aim.fns">'.$xml.'</nmswrap>';$doc=new DOMDocument();$prev=libxml_use_internal_errors(true);$loaded=$doc->loadXML($wrapped,LIBXML_NONET|LIBXML_COMPACT);libxml_clear_errors();libxml_use_internal_errors($prev);if(!$loaded||!$doc->documentElement)return null;$xp=new DOMXPath($doc);$messages=$xp->query('//*[local-name()="AIXMBasicMessage"]');if(!$messages||$messages->length===0)return null;$root=$messages->item(0);if(!$root instanceof DOMElement)return null;$nmsId=trim($root->getAttributeNS('http://www.opengis.net/gml/3.2','id'));if($nmsId==='')$nmsId=trim($root->getAttribute('gml:id'));if($nmsId==='')return null;$notams=$xp->query('.//*[local-name()="NOTAM"]',$root);if(!$notams||$notams->length===0)return null;$notam=$notams->item(0);if(!$notam instanceof DOMNode)return null;$extensions=$xp->query('.//*[local-name()="EventExtension"]',$root);$extension=($extensions&&$extensions->length>0)?$extensions->item(0):null;$startRaw=nmsFullChildText($notam,'effectiveStart');$endRaw=nmsFullChildText($notam,'effectiveEnd');$start=nmsFullDate($startRaw);$end=nmsFullDate($endRaw);$type=nmsFullChildText($notam,'type');if(($startRaw!==null&&$start===null)||($endRaw!==null&&strtoupper($endRaw)!=='PERM'&&$end===null))throw new RuntimeException('Invalid effective date in initial load.');$lastUpdated=$extension instanceof DOMNode?nmsFullFirstText($xp,'lastUpdated',$extension):null;$class=$extension instanceof DOMNode?nmsFullFirstText($xp,'classification',$extension):null;$core=['id'=>$nmsId];foreach($notam->childNodes as$child)if($child instanceof DOMElement)$core[$child->localName]=trim($child->textContent);$core['publisherNOF']=$notams->length===1&&$notam instanceof DOMElement?notamXmlPublisher($notam):null;if($extension instanceof DOMNode)foreach(['accountId','icaoLocation','classification','lastUpdated']as$key)$core[$key]=nmsFullFirstText($xp,$key,$extension);$issued=nmsFullDate($core['issued']??null);if($issued!==null)$core['issued']=str_replace(' ','T',$issued).'Z';$raw=json_encode(['type'=>'Feature','geometry'=>null,'properties'=>['coreNOTAMData'=>['notam'=>$core]],'aixm'=>$xml],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);return['raw_json'=>$raw,'nms_id'=>$nmsId,'series'=>nmsFullChildText($notam,'series'),'number'=>nmsFullChildText($notam,'number'),'year'=>nmsStoreInt(nmsFullChildText($notam,'year')),'notam_type'=>$type,'classification'=>nmsFullClass($class),'affected_fir'=>nmsFullChildText($notam,'affectedFir'),'location'=>nmsFullChildText($notam,'location'),'icao_location'=>$extension instanceof DOMNode?nmsFullFirstText($xp,'icaoLocation',$extension):null,'account_id'=>$extension instanceof DOMNode?nmsFullFirstText($xp,'accountId',$extension):null,'selection_code'=>nmsFullChildText($notam,'selectionCode'),'traffic'=>nmsFullChildText($notam,'traffic'),'purpose'=>nmsFullChildText($notam,'purpose'),'scope'=>nmsFullChildText($notam,'scope'),'minimum_fl'=>nmsStoreInt(nmsFullChildText($notam,'minimumFl')),'maximum_fl'=>nmsStoreInt(nmsFullChildText($notam,'maximumFl')),'effective_start'=>$start,'effective_end'=>$end,'effective_end_raw'=>$endRaw,'estimated'=>nmsFullChildText($notam,'estimated'),'schedule'=>nmsFullChildText($notam,'schedule'),'lower_limit'=>nmsFullChildText($notam,'lowerLimit'),'upper_limit'=>nmsFullChildText($notam,'upperLimit'),'coordinates_raw'=>nmsFullChildText($notam,'coordinates'),'radius_nm'=>nmsStoreFloat(nmsFullChildText($notam,'radius')),'notam_text'=>nmsFullChildText($notam,'text'),'last_updated'=>nmsFullDate($lastUpdated),'status'=>nmsRecordStatus($type,$start,$endRaw),'source'=>'FAA_NMS','environment'=>$environment];}

function nmsFullProgressPath(string$environment):string{return nmsCacheDir().DIRECTORY_SEPARATOR.'initial_progress_'.$environment.'.json';}
function nmsFullReadProgress(string$environment):?array{$path=nmsFullProgressPath($environment);if(!is_file($path))return null;$raw=@file_get_contents($path);$data=$raw===false?null:json_decode($raw,true);if(!is_array($data)||empty($data['xmlPath'])||!is_file((string)$data['xmlPath']))return null;return$data;}
function nmsFullWriteProgress(string$environment,array$state):void{$json=json_encode($state,JSON_UNESCAPED_SLASHES);if($json===false||@file_put_contents(nmsFullProgressPath($environment),$json,LOCK_EX)===false)throw new RuntimeException('Initial load progress could not be saved.');}
function nmsFullSnapshotInfo(string $xmlPath): array {
    $fh=@fopen($xmlPath,'rb');if(!$fh)throw new RuntimeException('Snapshot could not be read.');
    try{$head=fread($fh,262144);$size=(int)filesize($xmlPath);fseek($fh,max(0,$size-8192));$tail=stream_get_contents($fh,8192);}finally{fclose($fh);}
    if(!is_string($head)||!is_string($tail)||stripos($head,'<!DOCTYPE')!==false||stripos($head,'<!ENTITY')!==false)throw new RuntimeException('Invalid snapshot XML header.');
    $clean=preg_replace('/<\?.*?\?>|<!--.*?-->/s','',$head);
    if(!is_string($clean)||!preg_match('/^\s*<((?:[A-Za-z_][\w.-]*:)?FeatureCollection)\b([^>]*)>/s',$clean,$m))throw new RuntimeException('Snapshot FeatureCollection header missing.');
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$ok=$doc->loadXML($m[0].'</'.$m[1].'>',LIBXML_NONET);if(!$ok||!$doc->documentElement)throw new RuntimeException('Snapshot header is invalid.');$root=$doc->documentElement;$stamp=$root->getAttribute('timeStamp');$count=$root->getAttribute('numberReturned');}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    if(!preg_match('/^\d+$/D',$count)||(int)$count<1||!preg_match('/<\/'.preg_quote($m[1],'/').'>\s*$/D',$tail))throw new RuntimeException('Snapshot count/footer missing; completion cannot be verified.');
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$stamp))throw new RuntimeException('Snapshot timeStamp missing; delta handoff cannot be verified.');
    $date=new DateTimeImmutable($stamp);if(DateTimeImmutable::getLastErrors()!==false)throw new RuntimeException('Invalid snapshot timeStamp.');
    $date=$date->setTimezone(new DateTimeZone('UTC'));
    if($date->getTimestamp()>time()+30)throw new RuntimeException('Snapshot timeStamp is in the future.');
    return ['expected'=>(int)$count,'snapshotAt'=>$date->format('Y-m-d\TH:i:s\Z'),'catchupSince'=>$date->modify('-60 seconds')->format('Y-m-d\TH:i:s\Z')];
}

function nmsFullFindReusableXml(string$environment):?string{$files=glob(nmsCacheDir().DIRECTORY_SEPARATOR.'initial_'.$environment.'_*.bin.xml')?:[];usort($files,fn(string$a,string$b):int=>(int)@filemtime($b)<=>(int)@filemtime($a));foreach($files as$file){$mtime=@filemtime($file);if($mtime!==false&&(time()-$mtime)<=21600&&is_file($file)&&filesize($file)>0)return$file;}return null;}
function nmsFullPrepareState(string$environment):array{$existing=nmsFullReadProgress($environment);if($existing!==null){if(empty($existing['catchupSince'])){$existing=array_merge($existing,nmsFullSnapshotInfo((string)$existing['xmlPath']));nmsFullWriteProgress($environment,$existing);}return$existing;}$xmlPath=nmsFullFindReusableXml($environment);$downloadPath=null;$source='reused-timeout-snapshot';if($xmlPath===null){$source='faa-initial-load';$meta=nmsGet('/notams/il',['allowRedirect'=>'false'],null);if(!($meta['ok']??false))throw new RuntimeException((string)($meta['error']??'FAA NMS initial load request failed.'));$base=nmsCacheDir().DIRECTORY_SEPARATOR.'initial_'.$environment.'_'.getmypid().'_'.time();$downloadPath=$base.'.bin';$metaData=is_array($meta['data']??null)?$meta['data']:[];$contentUrl=$metaData['data']['url']??null;if(is_string($contentUrl)&&trim($contentUrl)!==''){$download=nmsDownloadToFile(nmsFullContentApiPath($contentUrl),$downloadPath);if(!($download['ok']??false))throw new RuntimeException((string)($download['error']??'FAA NMS initial load content download failed.'));}else{$payload=$meta['body']??null;if(!is_string($payload)||$payload==='')throw new RuntimeException('FAA NMS initial load payload was empty.');nmsFullWritePayload($payload,$downloadPath);} $xmlPath=nmsFullMaterializeXml($downloadPath);} $state=['environment'=>$environment,'xmlPath'=>$xmlPath,'downloadPath'=>$downloadPath,'byteOffset'=>0,'processed'=>0,'skipped'=>0,'phase'=>'import','startedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'updatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'source'=>$source];$state=array_merge($state,nmsFullSnapshotInfo($xmlPath));nmsFullWriteProgress($environment,$state);return$state;}
function nmsFullNextMessage($fh,int$offset):?array{if(fseek($fh,$offset)!==0)throw new RuntimeException('Initial load cursor could not seek to the saved position.');$buffer='';$baseOffset=$offset;$closeTag=null;$found=false;while(!feof($fh)){$chunk=fread($fh,262144);if($chunk===false)throw new RuntimeException('Initial load snapshot read failed.');if($chunk==='')break;$buffer.=$chunk;if(!$found){if(preg_match('/<(?:(?<p>[A-Za-z_][A-Za-z0-9_.-]*):)?AIXMBasicMessage\b/',$buffer,$m,PREG_OFFSET_CAPTURE)){$start=(int)$m[0][1];$prefix=isset($m['p'][0])&&is_string($m['p'][0])?$m['p'][0]:'';$baseOffset+=$start;$buffer=substr($buffer,$start);$closeTag='</'.($prefix!==''?$prefix.':':'').'AIXMBasicMessage>';$found=true;}elseif(strlen($buffer)>512){$drop=strlen($buffer)-512;$baseOffset+=$drop;$buffer=substr($buffer,-512);continue;}}if($found&&$closeTag!==null){$endPos=strpos($buffer,$closeTag);if($endPos!==false){$end=$endPos+strlen($closeTag);return['xml'=>substr($buffer,0,$end),'nextOffset'=>$baseOffset+$end];}}if(strlen($buffer)>16777216)throw new RuntimeException('One initial load record exceeded the safe parser buffer.');}if($found)throw new RuntimeException('Truncated initial load record.');return null;}
function nmsFullCleanupState(string$environment,array$state):void{@unlink(nmsFullProgressPath($environment));foreach(['xmlPath','downloadPath']as$key){$path=(string)($state[$key]??'');if($path!==''&&is_file($path))@unlink($path);}}
function nmsRunFullLoadSlice(int $limit=250,int $maxSeconds=7): array {
    $cfg=nmsPrivateConfig();$environment=$cfg['env'];$pdo=nmsDb();$sync=nmsSyncState($pdo,$environment);$active=nmsFullReadProgress($environment);$fh=null;
    if($active===null&&!empty($sync['last_full_load'])){
        $last=strtotime((string)$sync['last_full_load'].' UTC');
        if($last!==false&&(time()-$last)<86400)return ['ok'=>false,'complete'=>false,'rateLimitedLocally'=>true,'environment'=>$environment,'error'=>'Full load was already completed within the last 24 hours.','lastFullLoad'=>$sync['last_full_load']];
    }
    $limit=max(25,min(500,$limit));$maxSeconds=max(2,min(10,$maxSeconds));$started=microtime(true);
    try{
        $state=nmsFullPrepareState($environment);
        $since=new DateTimeImmutable((string)$state['catchupSince']);
        if(time()-$since->getTimestamp()>23*3600){nmsFullCleanupState($environment,$state);throw new RuntimeException('Expired snapshot retired; next cron will obtain a fresh snapshot. Cursor was not changed.');}
        if(($state['phase']??'import')!=='catchup'){
            $fh=@fopen((string)$state['xmlPath'],'rb');if(!$fh)throw new RuntimeException('Initial load snapshot could not be opened.');
            $offset=(int)($state['byteOffset']??0);$sliceProcessed=0;$eof=false;$sliceArchiveFailed=false;$pdo->beginTransaction();
            while($sliceProcessed<$limit&&(microtime(true)-$started)<$maxSeconds){
                $next=nmsFullNextMessage($fh,$offset);if($next===null){$eof=true;break;}
                $record=nmsFullNormalizeMessage((string)$next['xml'],$environment);if($record===null)throw new RuntimeException('Invalid NOTAM in initial load snapshot.');
                if(is_string($record['raw_json']??null)&&!ycArchiveRawNmsJson((string)$record['raw_json']))$sliceArchiveFailed=true;
                nmsFullUpsert($pdo,$record);$offset=(int)$next['nextOffset'];$sliceProcessed++;
            }
            $pdo->commit();fclose($fh);$fh=null;
            if($sliceArchiveFailed)nmsCronLog('WARNING raw NMS archive write failure during Initial Load slice.');
            $state['byteOffset']=$offset;$state['processed']=(int)($state['processed']??0)+$sliceProcessed;$state['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
            $expected=(int)$state['expected'];
            if(!$eof){nmsFullWriteProgress($environment,$state);return ['ok'=>true,'complete'=>false,'environment'=>$environment,'phase'=>'import','processed'=>$state['processed'],'skipped'=>(int)($state['skipped']??0),'sliceProcessed'=>$sliceProcessed,'expected'=>$expected,'progressPercent'=>min(99.9,round($state['processed']/$expected*100,1))];}
            if($expected<1||(int)$state['processed']!==$expected||(int)($state['skipped']??0)!==0)throw new RuntimeException('Initial load count mismatch; completion was not recorded.');
            $state['phase']='catchup';nmsFullWriteProgress($environment,$state);
        }
        $catchup=nmsRunDeltaSync(600,(string)$state['catchupSince']);
        if(!($catchup['ok']??false))return ['ok'=>false,'complete'=>false,'environment'=>$environment,'phase'=>'catchup','processed'=>(int)$state['processed'],'expected'=>$state['expected'],'error'=>$catchup['error']??'Full load catch-up failed.','catchup'=>$catchup];
        $completed=gmdate('Y-m-d H:i:s');
        $q=$pdo->prepare("UPDATE notam_sync_state SET last_full_load=:completed,last_error=NULL WHERE source='FAA_NMS' AND environment=:environment");$q->execute(['completed'=>$completed,'environment'=>$environment]);
        nmsFullCleanupState($environment,$state);
        return ['ok'=>true,'complete'=>true,'environment'=>$environment,'processed'=>(int)$state['processed'],'skipped'=>0,'expected'=>$state['expected'],'progressPercent'=>100,'snapshotAt'=>$state['snapshotAt'],'completedAt'=>$completed.'Z','geometryDiagnostics'=>$catchup['geometryDiagnostics']??null,'referenceResolution'=>$catchup['referenceResolution']??null,'catchup'=>$catchup];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        try{nmsStoreSyncError($pdo,$environment,'Full load slice failed: '.$e->getMessage());}catch(Throwable){}
        return ['ok'=>false,'complete'=>false,'environment'=>$environment,'error'=>'FAA NMS full load slice failed.','detail'=>$e->getMessage()];
    }finally{if(is_resource($fh))fclose($fh);}
}

function nmsCleanupTransientCaches(): array {
    $result=['removed'=>0,'freedBytes'=>0,'truncated'=>false,'completedAt'=>gmdate('Y-m-d\TH:i:s\Z')];
    $tmp=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR);$started=microtime(true);$visited=0;
    $rules=[['yulcaribe_metartaf','/^(?:metar|taf)_[A-Za-z0-9_-]+\.json$/D',0],['yulcaribe_wafs_png','/^[a-f0-9]{40}\.png$/D',1800],['yulcaribe_nms','/^adsb_\d{2}\.bin$/D',1800]];
    foreach($rules as[$name,$pattern,$ttl]){
        $dir=$tmp.DIRECTORY_SEPARATOR.$name;if(!is_dir($dir)||is_link($dir))continue;
        try{foreach(new DirectoryIterator($dir)as$file){
            if($file->isDot())continue;
            if(++$visited>10000||microtime(true)-$started>2){$result['truncated']=true;break 2;}
            if($file->isLink()||!$file->isFile()||!preg_match($pattern,$file->getFilename()))continue;
            if($ttl>0&&time()-$file->getMTime()<=$ttl)continue;
            $path=$file->getPathname();$fh=@fopen($path,'r+b');if(!$fh)continue;
            try{if(!flock($fh,LOCK_EX|LOCK_NB))continue;$stat=fstat($fh);$bytes=(int)($stat['size']??0);
                if($ttl>0&&time()-(int)($stat['mtime']??time())<=$ttl)continue;
                if($name==='yulcaribe_nms'){$done=$bytes>0&&ftruncate($fh,0);}else{$done=@unlink($path);}
                if($done){$result['removed']++;$result['freedBytes']+=$bytes;}
            }finally{flock($fh,LOCK_UN);fclose($fh);}
        }}catch(Throwable $e){nmsCronLog('WARNING cache cleanup: '.$e->getMessage());$result['error']='Some temporary files could not be cleaned.';}
        if($name==='yulcaribe_metartaf')@rmdir($dir);
    }
    return $result;
}

function nmsCronStatePath(string$environment):string{return ycNmsStatePath($environment);}
function nmsCronLogDir():string{return ycRuntimeDir('nms');}
function nmsCronCleanupLogs(int$days=3):void{ycRuntimeCleanup($days);}
function nmsCronCleanupLegacyLog(int$days=3):void{
    $path=dirname(__DIR__,3).'/nms_cron.log';
    if(!is_file($path)||is_link($path))return;
    $mtime=@filemtime($path);$cutoff=time()-max(1,$days)*86400;
    if($mtime!==false&&$mtime<$cutoff)@unlink($path);
}
function nmsCronLog(string$message):void{
    static$cleaned=false;
    if(!$cleaned){nmsCronCleanupLogs(3);nmsCronCleanupLegacyLog(3);$cleaned=true;}
    $level='INFO';$clean=trim($message);
    if(preg_match('/^(ERROR|WARNING|RECOVERED)\b:?\s*/',$clean,$m)){$level=$m[1];$clean=preg_replace('/^(ERROR|WARNING|RECOVERED)\b:?\s*/','',$clean)??$clean;}
    ycRuntimeLog('nms',$level,$clean);
    $line='['.gmdate('Y-m-d\TH:i:s\Z').'] '.$level.' '.$clean.PHP_EOL;
    fwrite(STDOUT,$line);fflush(STDOUT);
}

function nmsRecentIssueTimestamp(array $issue): int {
    $raw=(string)($issue['lastAt']??$issue['time']??'');
    $ts=$raw!==''?strtotime($raw):false;
    return $ts===false?0:$ts;
}
function nmsPruneRecentIssues(array $issues): array {
    $cutoff=time()-3*86400;$keep=[];
    foreach($issues as$issue){if(!is_array($issue))continue;if(nmsRecentIssueTimestamp($issue)<$cutoff)continue;$keep[]=$issue;}
    usort($keep,fn(array$a,array$b):int=>nmsRecentIssueTimestamp($b)<=>nmsRecentIssueTimestamp($a));
    return array_slice($keep,0,300);
}
function nmsStateIssues(array $state): array {
    $issues=[];$now=gmdate('Y-m-d\TH:i:s\Z');
    $error=trim((string)($state['error']??''));
    if(($state['ok']??null)===false&&$error!==''&&empty($state['rateLimitedLocally']))$issues[]=['type'=>'error','severity'=>'error','summary'=>$error,'details'=>['mode'=>$state['mode']??null]];
    $skipped=(int)($state['skipped']??0);
    if($skipped>0)$issues[]=['type'=>'skipped','severity'=>'warning','summary'=>$skipped.' NOTAM(s) skipped in the cron run.','details'=>['count'=>$skipped,'mode'=>$state['mode']??null]];
    $geometry=(int)($state['geometryWarnings']??0);
    if($geometry>0){$ids=[];foreach((array)($state['geometryIssues']??[])as$item){if(!is_array($item))continue;$id=trim((string)($item['ident']??$item['nmsId']??''));if($id!==''&&count($ids)<10)$ids[]=$id;}$issues[]=['type'=>'geometry','severity'=>'warning','summary'=>$geometry.' geometry warning(s) in the cron run.','details'=>['count'=>$geometry,'ids'=>$ids]];}
    $diagError=trim((string)($state['geometryDiagnostics']['error']??''));
    if($diagError!=='')$issues[]=['type'=>'geometry-diagnostics','severity'=>'warning','summary'=>$diagError,'details'=>[]];
    foreach($issues as&$issue){$issue['firstAt']=$now;$issue['lastAt']=$now;$issue['occurrences']=1;$issue['lastRunId']=$state['runId']??null;$basis=[$issue['type'],$issue['summary'],$issue['details']];$issue['fingerprint']=hash('sha256',json_encode($basis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:serialize($basis));}unset($issue);
    return$issues;
}
function nmsMergeRecentIssues(array $old,array $new): array {
    $merged=nmsPruneRecentIssues($old);
    foreach($new as$issue){$found=false;foreach($merged as&$existing){if(($existing['fingerprint']??'')!==($issue['fingerprint']??''))continue;$existing['lastAt']=$issue['lastAt'];$existing['occurrences']=(int)($existing['occurrences']??1)+1;$existing['lastRunId']=$issue['lastRunId']??null;$found=true;break;}unset($existing);if(!$found)$merged[]=$issue;}
    usort($merged,fn(array$a,array$b):int=>nmsRecentIssueTimestamp($b)<=>nmsRecentIssueTimestamp($a));
    return array_slice($merged,0,300);
}
function nmsCronWriteState(string $environment,array $state): void {
    $path=nmsCronStatePath($environment);$old=ycReadNmsState($environment);
    if(is_array($old)&&(!is_array($state['geometryDiagnostics']??null)||isset($state['geometryDiagnostics']['error']))) {
        if(is_array($old['geometryDiagnostics']??null))$state['geometryDiagnostics']=$old['geometryDiagnostics'];
    }
    if(isset($GLOBALS['nmsCacheCleanup']))$state['cacheCleanup']=$GLOBALS['nmsCacheCleanup'];
    $state['runId']=$GLOBALS['nmsCronRunId']??null;
    $state['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
    $state['recentIssues']=nmsMergeRecentIssues(is_array($old)?(array)($old['recentIssues']??[]):[],nmsStateIssues($state));
    ycRuntimeWriteJson($path,$state);
}
function nmsCronResultSummary(array$result):array{
    return[
        'ok'=>$result['ok']??null,'mode'=>$result['mode']??null,'environment'=>$result['environment']??null,
        'received'=>$result['received']??null,'processed'=>$result['processed']??null,'skipped'=>$result['skipped']??null,
        'geometryWarnings'=>$result['geometryWarnings']??null,'unresolvedGeometry'=>$result['geometryDiagnostics']['unresolvedCount']??null,
        'rawArchiveFailures'=>$result['rawArchiveFailures']??null,'syncThrough'=>$result['syncThrough']??null,
        'needsFullLoad'=>$result['needsFullLoad']??null,'rateLimitedLocally'=>$result['rateLimitedLocally']??null,
        'error'=>$result['error']??$result['detail']??null,
    ];
}

function nmsCronFullLoad(string$environment,int$maxRuntimeSeconds=900):array{$started=microtime(true);$rounds=0;$last=null;while((microtime(true)-$started)<$maxRuntimeSeconds){$rounds++;$last=nmsRunFullLoadSlice(500,10);if(!($last['ok']??false))return['rounds'=>$rounds]+$last;nmsCronWriteState($environment,['ok'=>true,'mode'=>'initial-load','running'=>!($last['complete']??false),'complete'=>(bool)($last['complete']??false),'processed'=>(int)($last['processed']??0),'skipped'=>(int)($last['skipped']??0),'expected'=>$last['expected']??null,'progressPercent'=>$last['progressPercent']??null,'rounds'=>$rounds]);if($last['complete']??false)return['rounds'=>$rounds]+$last;usleep(100000);}return['ok'=>true,'complete'=>false,'pausedForNextCron'=>true,'environment'=>$environment,'rounds'=>$rounds,'processed'=>(int)($last['processed']??0),'skipped'=>(int)($last['skipped']??0),'expected'=>$last['expected']??null,'progressPercent'=>$last['progressPercent']??null];}

$GLOBALS['nmsCronRunId']=gmdate('Ymd\THis\Z').'-'.getmypid();
$cfg=nmsPrivateConfig();$environment=$cfg['env'];$lockPath=nmsCacheDir().DIRECTORY_SEPARATOR.'cron_'.$environment.'.lock';$lock=@fopen($lockPath,'c+');if(!$lock){nmsCronLog('ERROR: NMS cron lock could not be opened.');exit(1);}if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);exit(0);}try{$GLOBALS['nmsCacheCleanup']=nmsCleanupTransientCaches();if($environment!=='production'){$result=['ok'=>false,'mode'=>'blocked','environment'=>$environment,'error'=>'Automatic NMS cron is enabled only for production.'];nmsCronWriteState($environment,$result);nmsCronLog('ERROR: '.json_encode(nmsCronResultSummary($result),JSON_UNESCAPED_SLASHES));exit(2);}$pdo=nmsDb();$state=nmsSyncState($pdo,$environment);$countStmt=$pdo->prepare("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment=:environment");$countStmt->execute(['environment'=>$environment]);$notamCount=(int)$countStmt->fetchColumn();if(nmsFullReadProgress($environment)!==null||$notamCount===0||empty($state['last_full_load'])){$existing=nmsFullReadProgress($environment);$resuming=is_array($existing);nmsCronWriteState($environment,['ok'=>true,'mode'=>$resuming?'initial-load':'initial-download','running'=>true,'complete'=>false,'processed'=>(int)($existing['processed']??0),'skipped'=>(int)($existing['skipped']??0),'expected'=>$existing['expected']??null,'progressPercent'=>null,'message'=>$resuming?'Existing FAA Initial Load snapshot is being resumed from saved progress.':'FAA Initial Load snapshot is being downloaded/prepared.']);nmsCronLog('Production baseline missing; Initial Load starting.');$result=nmsCronFullLoad($environment);nmsCronWriteState($environment,['ok'=>(bool)($result['ok']??false),'mode'=>'initial-load','running'=>!($result['complete']??false),'complete'=>(bool)($result['complete']??false),'processed'=>(int)($result['processed']??0),'skipped'=>(int)($result['skipped']??0),'expected'=>$result['expected']??null,'progressPercent'=>$result['progressPercent']??null,'pausedForNextCron'=>(bool)($result['pausedForNextCron']??false),'geometryDiagnostics'=>$result['geometryDiagnostics']??null,'referenceResolution'=>$result['referenceResolution']??null,'rateLimitedLocally'=>(bool)($result['rateLimitedLocally']??false),'error'=>$result['detail']??$result['error']??null]);}else{nmsCronLog('Baseline present; Delta Sync starting.');$result=nmsRunDeltaSync();if(($result['needsFullLoad']??false)===true){$result=nmsCronFullLoad($environment);$mode='recovery-full-load';}else$mode='delta';if(!is_array($result['geometryDiagnostics']??null)){try{$result['geometryDiagnostics']=nmsGeometryDiagnostics($pdo,$environment);}catch(Throwable$e){$result['geometryDiagnostics']=['unresolvedCount'=>0,'unresolved'=>[],'error'=>'Geometry diagnostics unavailable.'];}}nmsCronWriteState($environment,['ok'=>(bool)($result['ok']??false),'mode'=>$mode,'running'=>false,'processed'=>(int)($result['processed']??0),'received'=>isset($result['received'])?(int)$result['received']:null,'skipped'=>(int)($result['skipped']??0),'geometryWarnings'=>(int)($result['geometryWarnings']??0),'geometryIssues'=>is_array($result['geometryIssues']??null)?$result['geometryIssues']:[],'geometryDiagnostics'=>is_array($result['geometryDiagnostics']??null)?$result['geometryDiagnostics']:['unresolvedCount'=>0,'unresolved'=>[]],'referenceResolution'=>$result['referenceResolution']??null,'syncThrough'=>$result['syncThrough']??null,'retryAfterSeconds'=>$result['retryAfterSeconds']??null,'rateLimitedLocally'=>(bool)($result['rateLimitedLocally']??false),'rawArchiveFailures'=>(int)($result['rawArchiveFailures']??0),'error'=>$result['error']??$result['detail']??null]);}if(($result['ok']??false)===true)$result['retentionCleanup']=nmsCleanupOldNotams($pdo,$environment,3,false);nmsCronLog('Result: '.json_encode(nmsCronResultSummary($result),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));exit(($result['ok']??false)?0:1);}catch(Throwable$e){$result=['ok'=>false,'mode'=>'exception','environment'=>$environment,'error'=>$e->getMessage()];nmsCronWriteState($environment,$result);nmsCronLog('ERROR: '.json_encode(nmsCronResultSummary($result),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));exit(1);}finally{flock($lock,LOCK_UN);fclose($lock);}
