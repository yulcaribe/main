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
function ycRateLimit(string $bucket, float $perSecond, int $burst): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $packed=@inet_pton($ip);
    $identity=$packed===false?'unknown':bin2hex(strlen($packed)===16?substr($packed,0,8):$packed);
    $key=hash('sha256',$identity);
    $dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_nms';
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir))) ycRejectRequest(503,'İstek kontrolü kullanılamıyor.');
    $path=$dir.DIRECTORY_SEPARATOR.'limit_'.$bucket.'_'.$key[0].'.json';
    if (is_link($path)) ycRejectRequest(503,'İstek kontrolü kullanılamıyor.');
    $handle=@fopen($path,'c+b');
    if (!$handle) ycRejectRequest(503,'İstek kontrolü kullanılamıyor.');
    @chmod($path,0600);$locked=false;$retry=0;$failed=false;
    try {
        for($i=0;$i<25;$i++){if(flock($handle,LOCK_EX|LOCK_NB)){$locked=true;break;}usleep(2000);}
        if(!$locked) throw new RuntimeException('Limiter busy.');
        $raw=stream_get_contents($handle,262145);
        if($raw===false || strlen($raw)>262144) throw new RuntimeException('Limiter state too large.');
        $state=$raw===''?[]:json_decode($raw,true);
        if(!is_array($state)) throw new RuntimeException('Invalid limiter state.');
        $now=microtime(true);
        foreach($state as $id=>$value) if(!is_array($value) || ($now-(float)($value['at']??0))>1800) unset($state[$id]);
        if(!isset($state[$key]) && count($state)>=512){$retry=60;}
        else {
            $old=$state[$key]??['tokens'=>$burst,'at'=>$now];
            $tokens=min((float)$burst,(float)$old['tokens']+max(0.0,$now-(float)$old['at'])*$perSecond);
            if($tokens<1.0)$retry=max(1,(int)ceil((1.0-$tokens)/$perSecond));
            else $tokens-=1.0;
            $state[$key]=['tokens'=>$tokens,'at'=>$now];
            $json=json_encode($state,JSON_THROW_ON_ERROR);
            rewind($handle);
            if(!ftruncate($handle,0) || fwrite($handle,$json)!==strlen($json) || !fflush($handle)) throw new RuntimeException('Limiter write failed.');
        }
    } catch(Throwable $e) { error_log('[rate-limit] '.$e->getMessage());$failed=true; }
    finally { if($locked)flock($handle,LOCK_UN);fclose($handle); }
    if($failed){header('Retry-After: 1');ycRejectRequest(503,'İstek kontrolü kullanılamıyor; tekrar deneyin.');}
    if($retry){header('Retry-After: '.$retry);ycRejectRequest(429,'Çok sık istek. '.$retry.' saniye sonra tekrar deneyin.');}
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
if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET'], true)) {
    header('Allow: GET');
    ycRejectRequest(405, 'HTTP method not allowed.');
}
if (!in_array(strtolower(trim($_GET['action'] ?? 'feed')), ['feed','status','health'], true)) ycRejectRequest(400, 'Geçersiz action.');


header('X-YC-API-Resource: adsb');
ycRateLimit('adsb',15,120);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

const YC_ADSB_UPSTREAM = 'https://globe.theairtraffic.com/re-api/';
const YC_ADSB_SOURCE = 'TheAirTraffic';
const YC_ADSB_MAX_BYTES = 12000000;

function jsonOut(int $status, array $payload): never {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('[adsb] JSON response: '.json_last_error_msg());
        $status = 500;
        $json = '{"ok":false,"error":"Response could not be encoded."}';
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}

function parseBox(?string $raw): array {
    $raw = trim((string)$raw);
    if (!preg_match('/^-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?$/', $raw)) {
        jsonOut(400, ['ok'=>false,'error'=>'Geçersiz ADS-B box değeri.']);
    }
    $parts = array_map('floatval', explode(',', $raw));
    if (count($parts) !== 4) jsonOut(400, ['ok'=>false,'error'=>'ADS-B box dört koordinat içermeli.']);
    [$south,$north,$west,$east] = $parts;
    if ($south < -90 || $south > 90 || $north < -90 || $north > 90 || $west < -180 || $west > 180 || $east < -180 || $east > 180 || $south >= $north || $west >= $east) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B box sınır dışında.']);
    }
    if (($north - $south) > 60 || ($east - $west) > 120) {
        jsonOut(400, ['ok'=>false,'error'=>'ADS-B görünümü çok geniş. Haritada biraz yaklaşın.']);
    }
    return [$south,$north,$west,$east];
}

function boxString(array $box): string {
    return implode(',', array_map(static fn(float $v): string => number_format($v, 6, '.', ''), $box));
}

function fetchTheAirTraffic(string $box): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'body'=>'','contentType'=>'','error'=>'PHP cURL aktif değil.','ms'=>null];
    $target = YC_ADSB_UPSTREAM . '?binCraft&zstd&box=' . $box;
    $body = '';
    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/154 Safari/537.36',
        CURLOPT_REFERER => 'https://globe.theairtraffic.com/',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: */*','Cache-Control: no-cache','Pragma: no-cache','X-Requested-With: XMLHttpRequest'],
        CURLOPT_WRITEFUNCTION => static function($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > YC_ADSB_MAX_BYTES) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $started = microtime(true);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $ms = round((microtime(true) - $started) * 1000, 1);
    curl_close($ch);
    return ['ok'=>$ok !== false && $errno === 0 && $status === 200 && str_starts_with($body, "\x28\xB5\x2F\xFD"),'status'=>$status,'body'=>$body,'contentType'=>$contentType,'error'=>$error ?: null,'ms'=>$ms];
}

// Fixed slots bound disk usage to 64 MiB. A slot lock also coalesces identical requests.
function sharedAdsb(string $box): array {
    $key=hash('sha256',$box);$slot=hexdec(substr($key,0,2))%32;
    $dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_nms';
    $path=$dir.DIRECTORY_SEPARATOR.sprintf('adsb_%02d.bin',$slot);
    $failure=['ok'=>false,'status'=>503,'body'=>'','contentType'=>'','error'=>'ADS-B request is busy.','ms'=>null,'retryAfter'=>1,'cacheAgeSeconds'=>null];
    if(is_link($dir)||is_link($path))return $failure;
    $fh=@fopen($path,'c+b');if(!$fh)return $failure;@chmod($path,0600);$locked=false;
    try{
        $deadline=microtime(true)+0.4;
        do{if(flock($fh,LOCK_EX|LOCK_NB)){$locked=true;break;}usleep(10000);}while(microtime(true)<$deadline);
        if(!$locked)return $failure;
        $header=fgets($fh,2048);$meta=is_string($header)?json_decode(trim($header),true):null;$now=microtime(true);
        if(is_array($meta)&&($meta['key']??'')===$key){
            $age=$now-(float)($meta['at']??0);
            if($age>=0&&$age<2.0){
                if(!empty($meta['failed']))return $failure;
                $body=stream_get_contents($fh,2097153);
                if(is_string($body)&&strlen($body)<=2097152&&strlen($body)===(int)($meta['bytes']??-1)&&str_starts_with($body,"\x28\xB5\x2F\xFD"))return ['ok'=>true,'status'=>200,'body'=>$body,'contentType'=>'application/zstd','error'=>null,'ms'=>0,'cacheAgeSeconds'=>round($age,3)];
            }
        }
        $result=fetchTheAirTraffic($box);$result['cacheAgeSeconds']=0.0;
        // Never return stale aircraft on an upstream failure. Remember only a short failure backoff.
        $body=$result['ok']?(string)$result['body']:'';
        rewind($fh);
        if(!ftruncate($fh,0))throw new RuntimeException('ADS-B cache reset failed.');
        if(strlen($body)<=2097152){
            $meta=['key'=>$key,'at'=>microtime(true),'failed'=>!$result['ok'],'bytes'=>strlen($body)];
            $data=json_encode($meta,JSON_THROW_ON_ERROR)."\n".$body;
            $offset=0;while($offset<strlen($data)){$n=fwrite($fh,substr($data,$offset));if($n===false||$n===0)throw new RuntimeException('ADS-B cache write failed.');$offset+=$n;}
            fflush($fh);
        }
        return $result;
    }catch(Throwable $e){error_log('[adsb-cache] '.$e->getMessage());return $failure;}
    finally{if($locked)flock($fh,LOCK_UN);fclose($fh);}
}

$action = strtolower(trim((string)($_GET['action'] ?? 'feed')));
$box = boxString(parseBox((string)($_GET['box'] ?? '36.500000,37.300000,30.000000,31.200000')));
$result = sharedAdsb($box);

if(isset($result['retryAfter']))header('Retry-After: '.(int)$result['retryAfter']);
if ($action === 'status' || $action === 'health') {
    $looksLikeZstd = strlen($result['body']) >= 4 && substr($result['body'], 0, 4) === "\x28\xB5\x2F\xFD";
    jsonOut($result['ok'] ? 200 : (isset($result['retryAfter'])?503:502), [
        'ok'=>$result['ok'],'resource'=>'adsb','source'=>YC_ADSB_SOURCE,'mode'=>'binCraft+zstd',
        'upstreamStatus'=>$result['status'],'contentType'=>$result['contentType'] ?: null,'bytes'=>strlen($result['body']),
        'cacheAgeSeconds'=>$result['cacheAgeSeconds']??null,'zstdFrame'=>$looksLikeZstd,'ms'=>$result['ms'],'box'=>$box,
        'error'=>$result['ok'] ? null : 'ADS-B kaynağına erişilemedi.'
    ]);
}
if ($action !== 'feed') jsonOut(400, ['ok'=>false,'error'=>'Bilinmeyen ADS-B action.']);
if (!$result['ok']) {
    error_log('[adsb] upstream failed HTTP '.(string)$result['status'].' '.(string)($result['error'] ?? ''));
    jsonOut(isset($result['retryAfter'])?503:502, ['ok'=>false,'resource'=>'adsb','source'=>YC_ADSB_SOURCE,'error'=>'ADS-B verisi alınamadı.']);
}

http_response_code(200);
header('Content-Type: application/zstd');
header('Content-Length: '.strlen($result['body']));
header('X-YC-ADSB-Source: '.YC_ADSB_SOURCE);
header('X-YC-ADSB-Mode: binCraft+zstd');
header('X-YC-ADSB-Age: '.(string)($result['cacheAgeSeconds']??0));
echo $result['body'];
