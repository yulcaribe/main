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

// Cron reuses the exact map geometry/reference parsers without running HTTP dispatch.
if (PHP_SAPI !== 'cli') {
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
if (!in_array(strtolower(trim($_GET['action'] ?? 'list')), ['list','filters','detail','map','health'], true)) ycRejectRequest(400, 'Geçersiz action.');


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('X-YC-API-Resource: notam');
ycRateLimit('notam',10,120);
}

const NOTAM_RETENTION_DAYS = 3;

function out(int $status, array $payload): never {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('[notam] JSON response: '.json_last_error_msg());
        $status = 500;
        $json = '{"ok":false,"error":"Response could not be encoded."}';
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($status >= 400) header('Cache-Control: no-store, max-age=0');
    echo $json;
    exit;
}


function boundedNumber(string $key, float $min, float $max, ?float $default = null): float {
    $raw = $_GET[$key] ?? null;
    if ($raw === null && $default !== null) return $default;
    if (!is_string($raw) || !is_numeric($raw)) out(400, ['ok'=>false,'error'=>'Geçersiz sayı: '.$key]);
    $value = (float)$raw;
    if (!is_finite($value) || $value < $min || $value > $max) out(400, ['ok'=>false,'error'=>'Sınır dışında: '.$key]);
    return $value;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_mysql')) out(500, ['ok'=>false,'error'=>'Veritabanı kullanılamıyor.']);

    $path = dirname(__DIR__, 3) . '/data.php';
    if (!is_file($path)) out(500, ['ok'=>false,'error'=>'Veritabanı yapılandırması bulunamadı.']);
    $cfg = require $path;
    if (!is_array($cfg)) out(500, ['ok'=>false,'error'=>'Veritabanı yapılandırması geçersiz.']);

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']),
            $cfg['user'],
            $cfg['password'],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    } catch (Throwable $e) {
        error_log('[notam-db] ' . $e->getMessage());
        out(500, ['ok'=>false,'error'=>'Veritabanına bağlanılamadı.']);
    }
}

function utc(mixed $value): DateTimeImmutable {
    $raw = is_string($value) ? trim($value) : '';
    if ($raw === '') return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $raw)) out(400, ['ok'=>false,'error'=>'Geçersiz UTC.']);
    try {
        $date = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new RuntimeException('Invalid calendar date.');
        return $date->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) { out(400, ['ok'=>false,'error'=>'Geçersiz UTC.']); }
}

function ident(array $row): string {
    $series = strtoupper(trim((string)($row['series'] ?? '')));
    $number = strtoupper(trim((string)($row['number'] ?? '')));
    $year = trim((string)($row['year'] ?? ''));
    $value = ($number !== '' && $series !== '' && str_starts_with($number,$series)) ? $number : $series . $number;
    if ($value === '') return (string)($row['nms_id'] ?? 'NOTAM');
    if ($year !== '' && !preg_match('/\/\d{2}$/D', $value)) $value .= '/' . substr($year, -2);
    return $value;
}

function identKey(array $row): ?string {
    $series = strtoupper(trim((string)($row['series'] ?? '')));
    $number = strtoupper(trim((string)($row['number'] ?? '')));
    $year = trim((string)($row['year'] ?? ''));
    if (!preg_match('/^[A-Z]$/D',$series) || !preg_match('/^(?:'.$series.')?([0-9]{4})(?:\/([0-9]{2}))?$/D',$number,$m)) return null;
    if ($year!=='' && !preg_match('/^(?:[0-9]{2}|[0-9]{4})$/D',$year)) return null;
    $year2=$year!==''?substr($year,-2):($m[2]??null);
    if ($year2===null || (isset($m[2]) && $m[2]!==$year2)) return null;
    return $series.$m[1].'/'.$year2;
}

function columns(bool $text = true, bool $geometry = false): string {
    $cols = [
        'n.nms_id','n.series','n.number','n.year','n.notam_type','n.classification','n.affected_fir',
        'n.location','n.icao_location','n.account_id','n.selection_code','n.traffic','n.purpose','n.scope',
        'n.minimum_fl','n.maximum_fl','n.effective_start','n.effective_end','n.effective_end_raw','n.estimated',
        'n.schedule','n.lower_limit','n.upper_limit','n.coordinates_raw','n.radius_nm','n.status','n.last_updated'
    ];
    if ($text) $cols[] = 'n.notam_text';
    if ($geometry) $cols[] = 'ST_AsGeoJSON(n.geometry,6) AS geometry';
    return implode(',', $cols);
}

function estimatedEnd(array $row): bool {
    $raw = strtoupper(trim((string)($row['estimated'] ?? '')));
    if ($raw !== '' && !in_array($raw, ['0','FALSE','NO','N','NULL'], true)) return true;
    return str_contains(strtoupper((string)($row['effective_end_raw'] ?? '')), 'EST');
}

function item(array $row, bool $text = true): array {
    $result = [
        'id'=>(string)$row['nms_id'],
        'ident'=>ident($row),
        'type'=>$row['notam_type'] ?? null,
        'classification'=>$row['classification'] ?? null,
        'fir'=>$row['affected_fir'] ?? null,
        'location'=>$row['location'] ?? null,
        'icaoLocation'=>$row['icao_location'] ?? null,
        'selectionCode'=>$row['selection_code'] ?? null,
        'traffic'=>$row['traffic'] ?? null,
        'purpose'=>$row['purpose'] ?? null,
        'scope'=>$row['scope'] ?? null,
        'minimumFl'=>$row['minimum_fl'] === null ? null : (int)$row['minimum_fl'],
        'maximumFl'=>$row['maximum_fl'] === null ? null : (int)$row['maximum_fl'],
        'effectiveStart'=>$row['effective_start'] ?? null,
        'effectiveEnd'=>$row['effective_end'] ?? null,
        'effectiveEndRaw'=>$row['effective_end_raw'] ?? null,
        'estimatedEnd'=>estimatedEnd($row),
        'schedule'=>$row['schedule'] ?? null,
        'lowerLimit'=>$row['lower_limit'] ?? null,
        'upperLimit'=>$row['upper_limit'] ?? null,
        'coordinates'=>$row['coordinates_raw'] ?? null,
        'radiusNm'=>$row['radius_nm'] === null ? null : (float)$row['radius_nm'],
        'status'=>$row['status'] ?? null,
        'lastUpdated'=>$row['last_updated'] ?? null,
    ];
    if ($text) $result['text'] = $row['notam_text'] ?? null;
    return $result;
}

function temporalState(array $row, DateTimeImmutable $at, array $replacementIndex = [], array $cancellationIndex = []): string {
    $id = (string)($row['nms_id'] ?? '');
    $type = strtoupper(trim((string)($row['notam_type'] ?? '')));
    $ts = $at->getTimestamp();
    $start = !empty($row['effective_start']) ? strtotime((string)$row['effective_start'] . ' UTC') : false;
    $end = !empty($row['effective_end']) ? strtotime((string)$row['effective_end'] . ' UTC') : false;

    if ($start !== false && $start > $ts) return 'future';
    if ($type === 'C') return 'cancelled';
    if ($id !== '' && isset($replacementIndex[$id])) return 'replaced';
    if ($id !== '' && isset($cancellationIndex[$id])) return 'cancelled';
    if (strtoupper(trim((string)($row['effective_end_raw'] ?? ''))) !== 'PERM' && $end !== false && $end < $ts) return 'expired';
    return 'valid';
}

function flattenScheduleValue(mixed $value, array &$parts): void {
    if ($value === null || $value === '') return;
    if (is_scalar($value)) {
        $text = trim((string)$value);
        if ($text !== '') $parts[] = $text;
        return;
    }
    if (is_array($value)) foreach ($value as $child) flattenScheduleValue($child, $parts);
}

function scheduleText(mixed $raw): string {
    $text = trim((string)$raw);
    if ($text === '') return '';
    if (($text[0] ?? '') === '[' || ($text[0] ?? '') === '{') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $parts = [];
            flattenScheduleValue($decoded, $parts);
            if ($parts) return implode(' ', $parts);
        }
    }
    return $text;
}

function dayNumber(string $day): ?int {
    return match (strtoupper($day)) {
        'SUN'=>0,'MON'=>1,'TUE'=>2,'WED'=>3,'THU'=>4,'FRI'=>5,'SAT'=>6,
        default=>null,
    };
}

function expandDayToken(string $token): ?array {
    $token = strtoupper(trim($token, " ,"));
    if (preg_match('/^(SUN|MON|TUE|WED|THU|FRI|SAT)$/', $token, $m)) return [dayNumber($m[1])];
    if (preg_match('/^(SUN|MON|TUE|WED|THU|FRI|SAT)-(SUN|MON|TUE|WED|THU|FRI|SAT)$/', $token, $m)) {
        $start = dayNumber($m[1]); $end = dayNumber($m[2]);
        if ($start === null || $end === null) return null;
        $out = [$start];
        while (end($out) !== $end && count($out) < 7) $out[] = (end($out) + 1) % 7;
        return $out;
    }
    return null;
}

function parseClock(string $raw): ?int {
    if (!preg_match('/^(\d{2})(\d{2})$/', $raw, $m)) return null;
    $h=(int)$m[1]; $min=(int)$m[2];
    if ($h > 23 || $min > 59) return null;
    return $h*60+$min;
}

function parseTimeRange(string $token): ?array {
    $token = str_replace(['–','—','−'], '-', strtoupper(trim($token, " ,")));
    if ($token === 'H24') return [0,1440];
    if (!preg_match('/^(\d{4})-(\d{4})$/', $token, $m)) return null;
    $start=parseClock($m[1]); $end=parseClock($m[2]);
    if ($start === null || $end === null) return null;
    return [$start,$end];
}

function scheduleActivity(array $row, DateTimeImmutable $at): array {
    $raw = scheduleText($row['schedule'] ?? null);
    if ($raw === '') return ['state'=>'active','active'=>true,'parsed'=>true,'raw'=>null,'reason'=>'continuous'];

    $normalized = strtoupper(str_replace(['–','—','−'], '-', $raw));
    $normalized = preg_replace('/\s+/', ' ', trim($normalized));
    $normalized = preg_replace('/\bUTC\b/', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));

    if ($normalized === '') return ['state'=>'active','active'=>true,'parsed'=>true,'raw'=>$raw,'reason'=>'continuous'];
    if (preg_match('/\b(EXC|SR|SS|SUNRISE|SUNSET)\b/', $normalized)) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
    if (preg_match('/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)\b/', $normalized)) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'date_specific_schedule'];

    $tokens = preg_split('/\s+/', $normalized) ?: [];
    $clauses = [];
    if ($tokens && in_array($tokens[0], ['DLY','DAILY'], true)) {
        array_shift($tokens);
        if (!$tokens) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'missing_time'];
        foreach ($tokens as $token) {
            $range = parseTimeRange($token);
            if ($range === null) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
            $clauses[] = ['days'=>[0,1,2,3,4,5,6],'range'=>$range];
        }
    } else {
        $groupDays=[]; $groupRanges=[];
        $flush = static function() use (&$clauses,&$groupDays,&$groupRanges): bool {
            if (!$groupDays && !$groupRanges) return true;
            if (!$groupDays || !$groupRanges) return false;
            $days=array_values(array_unique($groupDays));
            foreach ($groupRanges as $range) $clauses[]=['days'=>$days,'range'=>$range];
            $groupDays=[]; $groupRanges=[];
            return true;
        };
        foreach ($tokens as $token) {
            $days = expandDayToken($token);
            if ($days !== null) {
                if ($groupRanges && !$flush()) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
                $groupDays=array_merge($groupDays,$days);
                continue;
            }
            $range = parseTimeRange($token);
            if ($range !== null) {
                if (!$groupDays) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'missing_day'];
                $groupRanges[]=$range;
                continue;
            }
            return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
        }
        if (!$flush()) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
    }

    if (!$clauses) return ['state'=>'unknown','active'=>null,'parsed'=>false,'raw'=>$raw,'reason'=>'unsupported_schedule'];
    $day=(int)$at->format('w');
    $prevDay=($day+6)%7;
    $minute=((int)$at->format('H'))*60+(int)$at->format('i');
    foreach ($clauses as $clause) {
        [$start,$end]=$clause['range'];
        $days=$clause['days'];
        if ($end === 1440 || $start < $end) {
            if (in_array($day,$days,true) && $minute >= $start && $minute < $end) return ['state'=>'active','active'=>true,'parsed'=>true,'raw'=>$raw,'reason'=>'schedule_match'];
        } elseif ($start > $end) {
            if ((in_array($day,$days,true) && $minute >= $start) || (in_array($prevDay,$days,true) && $minute < $end)) return ['state'=>'active','active'=>true,'parsed'=>true,'raw'=>$raw,'reason'=>'schedule_match'];
        } elseif (in_array($day,$days,true)) return ['state'=>'active','active'=>true,'parsed'=>true,'raw'=>$raw,'reason'=>'schedule_match'];
    }
    return ['state'=>'inactive','active'=>false,'parsed'=>true,'raw'=>$raw,'reason'=>'outside_schedule'];
}

function replacementTargetKey(array $row): ?string {
    return notamReferenceKey($row,'R');
}

function cancellationTargetKey(array $row): ?string {
    return notamReferenceKey($row,'C');
}
function notamReferenceKey(array $row,string $type): ?string {
    preg_match_all('/\bNOTAM'.$type.'\s+([A-Z][0-9]{4}\/[0-9]{2})\b/i',(string)($row['notam_text']??''),$matches);
    $targets=array_values(array_unique(array_map('strtoupper',$matches[1]??[])));
    return count($targets)===1 && $targets[0]!==identKey($row)?$targets[0]:null;
}
function notamIssued(array $row): ?int {
    $raw=json_decode((string)($row['raw_json']??''),true);
    $issued=$raw['properties']['coreNOTAMData']['notam']['issued']??null;
    if(!is_string($issued)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$issued))return null;
    try{$d=new DateTimeImmutable($issued);if(DateTimeImmutable::getLastErrors()!==false)return null;return $d->getTimestamp();}catch(Throwable){return null;}
}
function referenceChronology(array $event,array $target): bool {
    $issued=notamIssued($event);$previous=notamIssued($target);
    if($issued===null||$previous===null||$previous>=$issued||strtoupper((string)($target['notam_type']??''))==='C')return false;
    $eventYear=(int)gmdate('Y',$issued);$targetYear=(int)gmdate('Y',$previous);
    foreach([[$event,$eventYear],[$target,$targetYear]]as[$r,$y]){
        $stored=$r['year']??null;if($stored===null||((int)$stored!==$y&&(int)$stored!==$y%100))return false;
    }
    return $targetYear<=$eventYear;
}
function referencedFdcGeometry(PDO $pdo,array $row,string $environment,DateTimeImmutable $at): ?array {
    if(!preg_match('/\bSEE\s+FDC\s+([0-9]\/\d{4})\b/i',(string)($row['notam_text']??''),$m))return null;
    $issued=notamIssued($row);if($issued===null||$issued>$at->getTimestamp())return null;
    $q=$pdo->prepare("SELECT nms_id,number,year,notam_type,account_id,notam_text,raw_json,effective_start,effective_end,effective_end_raw,status,ST_AsGeoJSON(geometry,6) geometry FROM notams WHERE source='FAA_NMS' AND environment=:environment AND UPPER(COALESCE(number,''))=:reference LIMIT 3");
    $q->execute(['environment'=>$environment,'reference'=>$m[1]]);$matches=$q->fetchAll();
    if(count($matches)!==1)return null;$target=$matches[0];$targetIssued=notamIssued($target);
    if($targetIssued===null||$targetIssued>$issued||gmdate('y',$targetIssued)%10!==(int)$m[1][0]||strtoupper((string)($target['notam_type']??''))==='C'||($target['status']??'')==='cancelled')return null;
    // FDC is an explicit national accountability, not an inferred country/airport.
    if(strtoupper(trim((string)($target['account_id']??'')))!=='FDC'&&!preg_match('/^\s*!?FDC\s+'.preg_quote($m[1],'/').'\b/i',(string)($target['notam_text']??'')))return null;
    $year=(int)($target['year']??-1);if($year!==(int)gmdate('Y',$targetIssued)&&$year!==(int)gmdate('y',$targetIssued))return null;
    $start=empty($target['effective_start'])?false:strtotime((string)$target['effective_start'].' UTC');$end=empty($target['effective_end'])?false:strtotime((string)$target['effective_end'].' UTC');
    if(!$start||$start>$at->getTimestamp()||($end!==false&&$end<$at->getTimestamp())||(!$end&&strtoupper((string)($target['effective_end_raw']??''))!=='PERM'))return null;
    $g=json_decode((string)($target['geometry']??''),true);$raw=json_decode((string)($target['raw_json']??''),true);$fresh=$raw['geometry']??null;
    return is_array($g)&&is_array($fresh)&&validGeoJson($fresh)?normalizeGeometry($g):null;
}

// Publisher identity must be explicit provider metadata, never inferred from an airport/FIR/account.
function notamPublisherKey(mixed $value): ?string {
    $isLink=is_array($value);
    if (is_array($value)) {
        $links=[];
        foreach (['href','xlink:href','@href','@xlink:href'] as $key) {
            if (array_key_exists($key,$value)) {
                if (!is_string($value[$key])) return null;
                $links[]=trim($value[$key]);
            }
        }
        $links=array_values(array_unique($links));
        if (count($links)!==1) return null;
        $value=$links[0]; // A display title alone is not an identity.
    }
    if (!is_string($value)) return null;
    $value=trim($value);
    if ($value==='' || strlen($value)>512 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/',$value)) return null;
    if (in_array(strtoupper($value),['NONE','NULL','NIL','UNKN','UNKNOWN','XXXX','ZZZZ'],true)) return null;
    if (!$isLink && preg_match('/^[A-Z]{4}(?:[A-Z]{4})?$/D',$value)) return 'nof:'.$value;
    if (preg_match('/^urn:([a-z0-9][a-z0-9-]{0,31}):([^?#]+)$/iD',$value,$m)) {
        // Only the URN scheme/namespace are case insensitive; preserve the identifier itself.
        if (strtolower($m[1])==='uuid' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$m[2])) return null;
        $id=strtolower($m[1])==='uuid'?strtolower($m[2]):$m[2];
        return 'urn:'.strtolower($m[1]).':'.$id;
    }
    if (preg_match('~^https?://~i',$value) && filter_var($value,FILTER_VALIDATE_URL)) {
        $url=parse_url($value);
        if (isset($url['user']) || isset($url['pass'])) return null;
        return strtolower($url['scheme']).'://'.strtolower($url['host']).(isset($url['port'])?':'.$url['port']:'').($url['path']??'').(isset($url['query'])?'?'.$url['query']:'').(isset($url['fragment'])?'#'.$url['fragment']:'');
    }
    // Document-local #ids are not globally unique. No remote URL is ever dereferenced here.
    return null;
}

function notamXmlPublisher(DOMElement $notam): mixed {
    if (!in_array($notam->namespaceURI,['http://www.aixm.aero/schema/5.1','http://www.aixm.aero/schema/5.1/event'],true)) return null;
    $publisher=null;$count=0;
    foreach ($notam->childNodes as $child) {
        if (!$child instanceof DOMElement || $child->localName!=='publisherNOF' || $child->namespaceURI!==$notam->namespaceURI) continue;
        $count++;
        $href=$child->getAttributeNS('http://www.w3.org/1999/xlink','href');
        $publisher=$href!==''?['href'=>$href]:null;
    }
    return $count===1?$publisher:null;
}

function notamLegacyPublisher(array $raw,array $row): ?string {
    $xml=$raw['aixm']??null;
    if (!is_string($xml) || strlen($xml)>16777216 || !str_contains($xml,'publisherNOF') || !class_exists(DOMDocument::class)) return null;
    if (stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) return null;
    $wrapped='<nmswrap xmlns="http://www.aixm.aero/schema/5.1/message" xmlns:aixm="http://www.aixm.aero/schema/5.1" xmlns:event="http://www.aixm.aero/schema/5.1/event" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:fnse="http://www.aixm.aero/schema/5.1/extensions/FAA/FNSE" xmlns:fns="urn:us.gov.dot.faa.aim.fns">'.$xml.'</nmswrap>';
    $previous=libxml_use_internal_errors(true);
    try {
        $doc=new DOMDocument();
        if (!$doc->loadXML($wrapped,LIBXML_NONET|LIBXML_COMPACT)) return null;
        $xp=new DOMXPath($doc);$messages=$xp->query('//*[local-name()="AIXMBasicMessage"]');
        if (!$messages || $messages->length!==1) return null;
        $message=$messages->item(0);
        if (!$message instanceof DOMElement || $message->getAttributeNS('http://www.opengis.net/gml/3.2','id')!==(string)$row['nms_id']) return null;
        $nodes=$xp->query('.//*[local-name()="NOTAM"]',$message);
        if (!$nodes || $nodes->length!==1 || !($nodes->item(0) instanceof DOMElement)) return null;
        $notam=$nodes->item(0);$fields=[];
        foreach ($notam->childNodes as $child) if ($child instanceof DOMElement) $fields[$child->localName]=trim($child->textContent);
        if (identKey($fields)!==identKey($row) || ($fields['type']??'')!==strtoupper((string)$row['notam_type'])) return null;
        return notamPublisherKey(notamXmlPublisher($notam));
    } finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
}

function referenceScope(array $row): ?array {
    $account=trim((string)($row['account_id']??''));
    $fir=strtoupper(trim((string)($row['affected_fir']??'')));
    $location=strtoupper(trim((string)(($row['icao_location']??'')?:($row['location']??''))));
    if ($account==='' || $fir==='' || $location==='') return null;
    $raw=json_decode((string)($row['raw_json']??''),true);$core=$raw['properties']['coreNOTAMData']['notam']??null;
    if (!is_array($core)) return null;
    foreach (['id','series','number','year','type','accountId','affectedFir','icaoLocation','location'] as $key) if (isset($core[$key])&&!is_scalar($core[$key])) return null;
    $coreLocation=strtoupper(trim((string)(($core['icaoLocation']??'')?:($core['location']??''))));
    if (empty($row['nms_id']) || (string)($core['id']??'')!==(string)$row['nms_id'] || identKey($core)===null || identKey($core)!==identKey($row) || strtoupper((string)($core['type']??''))!==strtoupper((string)($row['notam_type']??'')) || trim((string)($core['accountId']??''))!==$account || strtoupper(trim((string)($core['affectedFir']??'')))!==$fir || $coreLocation!==$location) return null;
    $publisher=notamPublisherKey($core['publisherNOF']??null);
    // Older full imports flattened hyperlink attributes to empty text; recover only from their own raw message.
    if ($publisher===null && (!isset($core['publisherNOF']) || $core['publisherNOF']==='')) $publisher=notamLegacyPublisher($raw,$row);
    return $publisher===null?null:['account'=>$account,'fir'=>$fir,'reference_location'=>$location,'publisher'=>$publisher];
}

function unresolvedReference(array &$result,array $row,?string $target,string $reason): void {
    $d=&$result['diagnostics'];$d['unresolved']++;$d['reasons'][$reason]=($d['reasons'][$reason]??0)+1;
    if (count($d['items'])<20) $d['items'][]=['nmsId'=>(string)($row['nms_id']??''),'ident'=>ident($row),'reference'=>$target,'reason'=>$reason];
}

function resolveNotamReferences(PDO $pdo,array $events,string $environment,DateTimeImmutable $at): array {
    $result=['replacement'=>[],'cancellation'=>[],'byEvent'=>[],'diagnostics'=>['total'=>0,'resolved'=>0,'unresolved'=>0,'reasons'=>[],'items'=>[]]];
    $groups=[];
    foreach ($events as $row) {
        $type=strtoupper((string)($row['notam_type']??''));
        if (!in_array($type,['R','C'],true)) continue;
        $effective=$row['effective_start']??$row['last_updated']??null;
        $stamp=is_string($effective)?strtotime($effective.' UTC'):false;
        if ($stamp!==false && $stamp>$at->getTimestamp()) continue;
        $result['diagnostics']['total']++;
        $target=$type==='R'?replacementTargetKey($row):cancellationTargetKey($row);
        if ($target===null || !preg_match('/^([A-Z])([0-9]{4})\/([0-9]{2})$/D',$target,$m)) { unresolvedReference($result,$row,$target,'unsupported_reference');continue; }
        if ($stamp===false || (isset($row['environment']) && $row['environment']!==$environment)) { unresolvedReference($result,$row,$target,'event_scope_invalid');continue; }
        $issued=notamIssued($row);
        if ($issued===null || $issued>$at->getTimestamp()) { unresolvedReference($result,$row,$target,'chronology_invalid');continue; }
        $scope=referenceScope($row);
        if ($scope===null) { unresolvedReference($result,$row,$target,'publisher_or_scope_unverified');continue; }
        $key=json_encode([$scope['account'],$scope['fir'],$scope['reference_location'],$target],JSON_THROW_ON_ERROR);
        if (!isset($groups[$key])) $groups[$key]=['lookup'=>[$scope['account'],$scope['fir'],$scope['reference_location'],$m[1],$m[2],$m[2].'/'.$m[3],$target,2000+(int)$m[3],(int)$m[3]],'events'=>[]];
        $groups[$key]['events'][]=['row'=>$row,'target'=>$target,'scope'=>$scope,'prefix'=>$type==='R'?'replacement':'cancellation'];
    }
    foreach (array_chunk(array_values($groups),100) as $chunk) {
        $wanted=[];$params=[];
        foreach ($chunk as $i=>$group) {
            $wanted[]='SELECT ? lookup_id,? account,? fir,? reference_location,? series,? serial,? serial_slash,? target,? year4,? year2';
            array_push($params,$i,...$group['lookup']);
        }
        $params[]=$environment;
        // A bounded batch replaces one SELECT per reference. A truncated batch cannot prove uniqueness.
        $limit=count($chunk)*4;
        $sql="SELECT w.lookup_id,n.nms_id,n.series,n.number,n.year,n.notam_type,n.raw_json,n.account_id,n.affected_fir,n.location,n.icao_location FROM (".implode(' UNION ALL ',$wanted).") w JOIN notams n ON n.account_id=w.account AND n.affected_fir=w.fir AND COALESCE(NULLIF(n.icao_location,''),n.location)=w.reference_location AND n.series=w.series AND (n.number=w.serial OR n.number=w.serial_slash OR n.number=w.target) AND (n.year=w.year4 OR n.year=w.year2 OR n.year IS NULL) WHERE n.source='FAA_NMS' AND n.environment=? LIMIT ".($limit+1);
        $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$overflow=count($rows)>$limit;$candidates=[];
        if (!$overflow) foreach ($rows as $row) $candidates[(int)$row['lookup_id']][]=['row'=>$row,'scope'=>referenceScope($row)];
        foreach ($chunk as $i=>$group) foreach ($group['events'] as $entry) {
            $event=$entry['row'];$target=$entry['target'];$matches=[];$unknown=false;
            foreach ($candidates[$i]??[] as $candidate) {
                if ($candidate['scope']===null || identKey($candidate['row'])!==$target) { $unknown=true;continue; }
                if ($candidate['scope']===$entry['scope']) $matches[]=$candidate['row'];
            }
            $reason=$overflow?'candidate_limit':($unknown?'candidate_unverified':(count($matches)>1?'ambiguous_target':null));
            if ($reason===null && !$matches) $reason=empty($candidates[$i])?'target_missing':'publisher_or_scope_mismatch';
            if ($reason===null && ((string)$matches[0]['nms_id']===(string)$event['nms_id'] || !referenceChronology($event,$matches[0]))) $reason='chronology_invalid';
            if ($reason!==null) { unresolvedReference($result,$event,$target,$reason);continue; }
            $id=(string)$matches[0]['nms_id'];$prefix=$entry['prefix'];
            $meta=[$prefix.'Id'=>(string)$event['nms_id'],$prefix.'Ident'=>ident($event),'effectiveStart'=>$event['effective_start']??$event['last_updated']??null];
            $result[$prefix][$id]??=$meta;
            $result['byEvent'][(string)$event['nms_id']]=['id'=>$id,'ident'=>$target];
            $result['diagnostics']['resolved']++;
        }
    }
    return $result;
}

function referenceBundle(PDO $pdo,DateTimeImmutable $at): array {
    static $cachedPdo=null,$cachedAt=null,$cached=null;
    $time=$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if ($cachedPdo===$pdo && $cachedAt===$time && $cached!==null) return $cached;
    $stmt=$pdo->prepare("SELECT nms_id,series,number,year,notam_type,notam_text,raw_json,effective_start,last_updated,account_id,affected_fir,location,icao_location FROM notams WHERE source='FAA_NMS' AND environment='production' AND UPPER(COALESCE(notam_type,'')) IN ('R','C') AND COALESCE(effective_start,last_updated)<=:at ORDER BY COALESCE(effective_start,last_updated),nms_id");
    $stmt->execute(['at'=>$time]);
    $cached=resolveNotamReferences($pdo,$stmt->fetchAll(PDO::FETCH_ASSOC),'production',$at);
    $cachedPdo=$pdo;$cachedAt=$time;
    return $cached;
}
function replacementIndex(PDO $pdo,DateTimeImmutable $at): array { return referenceBundle($pdo,$at)['replacement']; }
function cancellationIndex(PDO $pdo,DateTimeImmutable $at): array { return referenceBundle($pdo,$at)['cancellation']; }
function referenceSummary(PDO $pdo,DateTimeImmutable $at): array {
    $summary=referenceBundle($pdo,$at)['diagnostics'];unset($summary['items']);
    return ['scope'=>'dataset']+$summary;
}

function addIndexExclusion(array $index, array &$where, array &$params, string $prefix): void {
    if (!$index) return;
    $holders=[]; $i=0;
    foreach(array_keys($index) as $id) {$key=$prefix.'_'.$i++;$holders[]=':'.$key;$params[$key]=$id;}
    if ($holders) $where[]='n.nms_id NOT IN ('.implode(',',$holders).')';
}

function addIndexOnly(array $index, array &$where, array &$params, string $prefix): bool {
    if (!$index) return false;
    $holders=[]; $i=0;
    foreach(array_keys($index) as $id) {$key=$prefix.'_'.$i++;$holders[]=':'.$key;$params[$key]=$id;}
    if ($holders) $where[]='n.nms_id IN ('.implode(',',$holders).')';
    return (bool)$holders;
}

function coverageInfo(PDO $pdo, DateTimeImmutable $at): array {
    static $baselineLoaded=false;
    static $baseline=null;
    if(!$baselineLoaded) {
        $baselineLoaded=true;
        try {
            $stmt=$pdo->query("SELECT last_full_load FROM notam_sync_state WHERE source='FAA_NMS' AND environment='production' LIMIT 1");
            $value=$stmt?->fetchColumn();
            $baseline=is_string($value)&&$value!=='' ? $value : null;
        } catch(Throwable) {$baseline=null;}
    }
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $delta=$at->getTimestamp()-$now->getTimestamp();
    $mode=$delta>120 ? 'future' : ($delta<-120 ? 'historical' : 'current');
    $retentionStart=$now->modify('-'.NOTAM_RETENTION_DAYS.' days');
    $completeFrom=$retentionStart;
    if($baseline!==null) {
        try {$baselineDate=new DateTimeImmutable($baseline,new DateTimeZone('UTC'));if($baselineDate>$completeFrom)$completeFrom=$baselineDate;} catch(Throwable) {}
    }
    return ['mode'=>$mode,'retentionDays'=>NOTAM_RETENTION_DAYS,'completeFromUtc'=>$completeFrom->format('c'),'complete'=>$mode!=='historical' || $at >= $completeFrom,'publishedOnly'=>$mode==='future','baselineUtc'=>$baseline];
}

function addExact(array &$where, array &$params, string $column, string $key, string $value, string $regex): void {
    $value = strtoupper(trim($value));
    if ($value !== '' && !preg_match($regex, $value)) out(400,['ok'=>false,'error'=>'Geçersiz filtre: '.$key]);
    if ($value !== '') {$where[] = "$column=:$key";$params[$key] = $value;}
}

function listAction(PDO $pdo): never {
    $at = utc($_GET['at'] ?? '');
    $state = strtolower((string)($_GET['state'] ?? 'valid'));
    if (!in_array($state,['valid','future','expired','cancelled','replaced','all'],true)) out(400,['ok'=>false,'error'=>'Geçersiz state.']);
    $pageRaw = $_GET['page'] ?? '1';
    if (!preg_match('/^[1-9][0-9]{0,6}$/D',$pageRaw)) out(400,['ok'=>false,'error'=>'Geçersiz sayfa.']);
    $page = (int)$pageRaw;
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $where = ["n.source='FAA_NMS'", "n.environment='production'"];
    $params = [];
    $time = $at->format('Y-m-d H:i:s');
    $replacements=replacementIndex($pdo,$at);
    $cancellations=cancellationIndex($pdo,$at);

    if ($state === 'valid') {
        $where[] = "UPPER(COALESCE(n.notam_type,''))<>'C'";
        $where[] = '(n.effective_start IS NULL OR n.effective_start<=:at_start)';
        $where[] = "(UPPER(COALESCE(n.effective_end_raw,''))='PERM' OR n.effective_end IS NULL OR n.effective_end>=:at_end)";
        $params['at_start'] = $time;$params['at_end'] = $time;
        addIndexExclusion($replacements,$where,$params,'repl');addIndexExclusion($cancellations,$where,$params,'cancel');
    } elseif ($state === 'future') {
        $where[] = "UPPER(COALESCE(n.notam_type,''))<>'C'";$where[] = 'n.effective_start>:at_start';$params['at_start'] = $time;
        addIndexExclusion($replacements,$where,$params,'repl');addIndexExclusion($cancellations,$where,$params,'cancel');
    } elseif ($state === 'expired') {
        $where[] = "UPPER(COALESCE(n.notam_type,''))<>'C'";$where[] = "UPPER(COALESCE(n.effective_end_raw,''))<>'PERM'";$where[] = 'n.effective_end<:at_end';$params['at_end'] = $time;
        addIndexExclusion($replacements,$where,$params,'repl');addIndexExclusion($cancellations,$where,$params,'cancel');
    } elseif ($state === 'cancelled') {
        if(!addIndexOnly($cancellations,$where,$params,'only_cancel')) out(200,['ok'=>true,'source'=>'FAA NMS','state'=>$state,'atUtc'=>$at->format('c'),'coverage'=>coverageInfo($pdo,$at),'referenceResolution'=>referenceSummary($pdo,$at),'items'=>[],'paging'=>['page'=>1,'limit'=>$limit,'returned'=>0,'total'=>0,'pages'=>0,'hasPrevious'=>false,'hasNext'=>false]]);
    } elseif ($state === 'replaced') {
        if(!addIndexOnly($replacements,$where,$params,'only_repl')) out(200,['ok'=>true,'source'=>'FAA NMS','state'=>$state,'atUtc'=>$at->format('c'),'coverage'=>coverageInfo($pdo,$at),'referenceResolution'=>referenceSummary($pdo,$at),'items'=>[],'paging'=>['page'=>1,'limit'=>$limit,'returned'=>0,'total'=>0,'pages'=>0,'hasPrevious'=>false,'hasNext'=>false]]);
    }

    addExact($where,$params,"UPPER(COALESCE(n.affected_fir,''))",'fir',(string)($_GET['fir'] ?? ''),'/^[A-Z0-9]{4}$/');
    addExact($where,$params,"UPPER(COALESCE(n.notam_type,''))",'type',(string)($_GET['type'] ?? ''),'/^[A-Z]{1,4}$/');
    addExact($where,$params,"UPPER(COALESCE(n.classification,''))",'classification',(string)($_GET['classification'] ?? ''),'/^[A-Z0-9_ -]{1,40}$/');
    addExact($where,$params,"UPPER(COALESCE(n.scope,''))",'scope',(string)($_GET['scope'] ?? ''),'/^[A-Z]{1,8}$/');
    addExact($where,$params,"UPPER(COALESCE(n.traffic,''))",'traffic',(string)($_GET['traffic'] ?? ''),'/^[A-Z]{1,8}$/');
    addExact($where,$params,"UPPER(COALESCE(n.purpose,''))",'purpose',(string)($_GET['purpose'] ?? ''),'/^[A-Z]{1,12}$/');
    addExact($where,$params,"UPPER(COALESCE(n.selection_code,''))",'selection',(string)($_GET['selection_code'] ?? ''),'/^[A-Z0-9]{1,12}$/');

    $icao = strtoupper(trim((string)($_GET['icao'] ?? '')));
    if ($icao!=='' && !preg_match('/^[A-Z0-9]{4}$/D',$icao)) out(400,['ok'=>false,'error'=>'Geçersiz ICAO.']);
    if (preg_match('/^[A-Z0-9]{4}$/', $icao)) {$where[] = "(UPPER(COALESCE(n.icao_location,''))=:icao OR UPPER(COALESCE(n.location,''))=:location)";$params['icao'] = $icao;$params['location'] = $icao;}
    $q = trim((string)($_GET['q'] ?? ''));
    if (strlen($q)>200) out(400,['ok'=>false,'error'=>'Arama en fazla 200 bayt olabilir.']);
    if ($q !== '') {$where[] = '(n.nms_id LIKE :q1 ESCAPE \'!\' OR n.notam_text LIKE :q2 ESCAPE \'!\')';$params['q1'] = $params['q2'] = '%' . strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';}

    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM notams n WHERE ' . $whereSql);$count->execute($params);$total = (int)$count->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / $limit)));
    $offset = ($page - 1) * $limit;
    $order = match ((string)($_GET['sort'] ?? 'updated_desc')) {'start_asc' => 'n.effective_start ASC','start_desc' => 'n.effective_start DESC','ident' => 'n.series,n.number',default => 'n.last_updated DESC'};
    $stmt = $pdo->prepare('SELECT ' . columns(true,false) . ' FROM notams n WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);$stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch()) {
        $entry = item($row, true);$entry['temporalState'] = temporalState($row, $at, $replacements, $cancellations);
        $activity=scheduleActivity($row,$at);$entry['activityState']=$activity['state'];$entry['activityActive']=$activity['active'];$entry['scheduleInterpreted']=$activity['parsed'];
        $id=(string)$row['nms_id'];if(isset($replacements[$id])) $entry['replacedBy']=$replacements[$id];if(isset($cancellations[$id])) $entry['cancelledBy']=$cancellations[$id];$items[] = $entry;
    }
    $pages = $total > 0 ? (int)ceil($total / $limit) : 0;
    out(200, ['ok'=>true,'source'=>'FAA NMS','displaySource'=>'FAA.GOV NOTAM SERVICE','state'=>$state,'atUtc'=>$at->format('c'),'coverage'=>coverageInfo($pdo,$at),'referenceResolution'=>referenceSummary($pdo,$at),'items'=>$items,'paging'=>['page'=>$page,'limit'=>$limit,'returned'=>count($items),'total'=>$total,'pages'=>$pages,'hasPrevious'=>$page>1,'hasNext'=>$page<$pages]]);
}

function filtersAction(PDO $pdo): never {
    $options = [];
    foreach (['type'=>'notam_type','classification'=>'classification','scope'=>'scope','traffic'=>'traffic'] as $key=>$column) {
        $stmt = $pdo->query("SELECT DISTINCT $column value FROM notams WHERE source='FAA_NMS' AND environment='production' AND $column IS NOT NULL AND $column<>'' ORDER BY $column LIMIT 200");
        $options[$key] = array_values(array_filter(array_column($stmt->fetchAll(), 'value')));
    }
    out(200, ['ok'=>true,'source'=>'FAA NMS','displaySource'=>'FAA.GOV NOTAM SERVICE','options'=>$options]);
}

function detailAction(PDO $pdo): never {
    $id = trim((string)($_GET['id'] ?? ''));$at=utc($_GET['at'] ?? '');
    $stmt = $pdo->prepare('SELECT ' . columns(true,false) . " FROM notams n WHERE n.source='FAA_NMS' AND n.environment='production' AND n.nms_id=:id LIMIT 1");$stmt->execute(['id'=>$id]);$row = $stmt->fetch();
    if (!$row) out(404, ['ok'=>false,'error'=>'NOTAM bulunamadı.']);
    $replacements=replacementIndex($pdo,$at);$cancellations=cancellationIndex($pdo,$at);$entry=item($row,true);$entry['temporalState']=temporalState($row,$at,$replacements,$cancellations);
    $activity=scheduleActivity($row,$at);$entry['activityState']=$activity['state'];$entry['activityActive']=$activity['active'];$entry['scheduleInterpreted']=$activity['parsed'];
    if(isset($replacements[$id]))$entry['replacedBy']=$replacements[$id];if(isset($cancellations[$id]))$entry['cancelledBy']=$cancellations[$id];
    out(200, ['ok'=>true,'source'=>'FAA NMS','displaySource'=>'FAA.GOV NOTAM SERVICE','atUtc'=>$at->format('c'),'coverage'=>coverageInfo($pdo,$at),'referenceResolution'=>referenceSummary($pdo,$at),'notam'=>$entry]);
}

function normalizeLon(float $lon): float {
    if (!is_finite($lon)) out(400, ['ok'=>false,'error'=>'Geçersiz boylam.']);
    $lon = fmod($lon, 360.0);
    return $lon > 180.0 ? $lon - 360.0 : ($lon < -180.0 ? $lon + 360.0 : $lon);
}
function circlePolygon(float $lon, float $lat, float $radiusNm, int $steps = 60): array {
    $earthRadiusNm = 3440.065;$angularDistance = max(0.0, $radiusNm) / $earthRadiusNm;$latRad = deg2rad($lat);$lonRad = deg2rad($lon);$ring = [];
    for ($i = 0; $i <= $steps; $i++) {$bearing = deg2rad(($i / $steps) * 360.0);$sinLat2 = sin($latRad) * cos($angularDistance) + cos($latRad) * sin($angularDistance) * cos($bearing);$lat2 = asin(max(-1.0, min(1.0, $sinLat2)));$lon2 = $lonRad + atan2(sin($bearing) * sin($angularDistance) * cos($latRad),cos($angularDistance) - sin($latRad) * sin($lat2));$ring[] = [round(normalizeLon(rad2deg($lon2)),6), round(rad2deg($lat2),6)];}
    return ['type'=>'Polygon','coordinates'=>[$ring]];
}
function coordinateRegex(): string {return '/(?<![A-Z0-9.])(?:[0-9]{6}(?:\.[0-9]+)?[NS][ \t]*[0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][ \t]*[0-9]{5}(?:\.[0-9]+)?[EW]|[0-9]{2}\s+[0-9]{2}\s+[0-9]{2}(?:\.[0-9]+)?[NS]\s+[0-9]{3}\s+[0-9]{2}\s+[0-9]{2}(?:\.[0-9]+)?[EW])(?![A-Z0-9.])/i';}
function parseCoordinate(string $token): ?array {
    $token=preg_replace('/\s+/', '', strtoupper(trim($token)));
    if (!is_string($token)) return null;
    if (preg_match('/^([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2}(?:\.[0-9]+)?)([EW])$/D',$token,$m)) {
        $dLat=(int)$m[1];$mLat=(float)$m[2];$sLat=0.0;$ns=$m[3];$dLon=(int)$m[4];$mLon=(float)$m[5];$sLon=0.0;$ew=$m[6];
    } elseif (preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([EW])$/D',$token,$m)) {
        $dLat=(int)$m[1];$mLat=(float)$m[2];$sLat=(float)$m[3];$ns=$m[4];$dLon=(int)$m[5];$mLon=(float)$m[6];$sLon=(float)$m[7];$ew=$m[8];
    } else return null;
    if ($mLat>=60 || $mLon>=60 || $sLat>=60 || $sLon>=60) return null;
    $lat=$dLat+$mLat/60+$sLat/3600;$lon=$dLon+$mLon/60+$sLon/3600;
    if (!is_finite($lat) || !is_finite($lon) || $lat>90 || $lon>180) return null;
    return [$ew==='W'?-$lon:$lon,$ns==='S'?-$lat:$lat];
}

function extractCoordinates(?string $text, bool $dedupe = true): array {$text = strtoupper(trim((string)$text));if ($text === '' || !preg_match_all(coordinateRegex(), $text, $matches)) return [];$coords = [];$seen = [];foreach ($matches[0] as $token) {$coord = parseCoordinate((string)$token);if ($coord === null) return [];if (!$dedupe) { $coords[] = $coord; continue; }$key = sprintf('%.6F,%.6F', $coord[0], $coord[1]);if (!isset($seen[$key])) { $seen[$key] = true; $coords[] = $coord; }}return $coords;}
function polygonFromCoordinates(array $coords): ?array {
    if (count($coords)<3 || count($coords)>256) return null;
    $ring=array_values($coords);
    if ($ring[0]!==$ring[count($ring)-1]) $ring[]=$ring[0];
    $n=count($ring)-1;$seen=[];$plane=[];$area=0.0;
    foreach($ring as $i=>$p){
        if(!validPosition($p))return null;
        $x=(float)$p[0];if($i>0){while($x-$plane[$i-1][0]>180)$x-=360;while($x-$plane[$i-1][0]<-180)$x+=360;}
        $plane[]=[$x,(float)$p[1]];
        if($i<$n){$key=sprintf('%.7F,%.7F',$p[0],$p[1]);if(isset($seen[$key]))return null;$seen[$key]=true;}
    }
    if(count($seen)<3 || abs($plane[0][0]-$plane[$n][0])>0.000001)return null;
    $cross=static fn(array$a,array$b,array$c):float=>($b[0]-$a[0])*($c[1]-$a[1])-($b[1]-$a[1])*($c[0]-$a[0]);
    $on=static fn(array$a,array$b,array$p):bool=>$p[0]>=min($a[0],$b[0])-1e-10&&$p[0]<=max($a[0],$b[0])+1e-10&&$p[1]>=min($a[1],$b[1])-1e-10&&$p[1]<=max($a[1],$b[1])+1e-10;
    for($i=0;$i<$n;$i++){
        $a=$plane[$i];$b=$plane[$i+1];$area+=$a[0]*$b[1]-$b[0]*$a[1];
        for($j=$i+2;$j<$n;$j++){
            if($i===0&&$j===$n-1)continue;
            $c=$plane[$j];$d=$plane[$j+1];$abC=$cross($a,$b,$c);$abD=$cross($a,$b,$d);$cdA=$cross($c,$d,$a);$cdB=$cross($c,$d,$b);
            if(($abC*$abD<0&&$cdA*$cdB<0)||(abs($abC)<1e-10&&$on($a,$b,$c))||(abs($abD)<1e-10&&$on($a,$b,$d))||(abs($cdA)<1e-10&&$on($c,$d,$a))||(abs($cdB)<1e-10&&$on($c,$d,$b)))return null;
        }
    }
    return abs($area)>1e-10?['type'=>'Polygon','coordinates'=>[$ring]]:null;
}
function validPosition(mixed $position): bool {
    return is_array($position)&&array_is_list($position)&&count($position)>=2
        && (is_int($position[0])||is_float($position[0]))&&(is_int($position[1])||is_float($position[1]))
        && is_finite((float)$position[0])&&is_finite((float)$position[1])&&abs($position[0])<=180&&abs($position[1])<=90;
}
function validGeoJson(array $g, int $depth=0, ?int &$remaining=null): bool {
    if($remaining===null)$remaining=50000;
    if($depth>8 || --$remaining<0)return false;
    $type=$g['type']??null;
    if($type==='GeometryCollection'){
        $items=$g['geometries']??null;if(!is_array($items)||!array_is_list($items)||!$items)return false;
        foreach($items as$item)if(!is_array($item)||!validGeoJson($item,$depth+1,$remaining))return false;
        return true;
    }
    $c=$g['coordinates']??null;
    if($type==='Point')return validPosition($c);
    if(!is_array($c)||!array_is_list($c)||!$c)return false;
    $line=static function(mixed$v,int$min,bool$closed=false)use(&$remaining):bool{
        if(!is_array($v)||!array_is_list($v)||count($v)<$min)return false;
        foreach($v as$p)if(--$remaining<0||!validPosition($p))return false;
        return !$closed||array_slice($v[0],0,2)===array_slice($v[count($v)-1],0,2);
    };
    if($type==='MultiPoint')return $line($c,1);
    if($type==='LineString')return $line($c,2);
    if($type==='MultiLineString'){foreach($c as$v)if(!$line($v,2))return false;return true;}
    if($type==='Polygon'){foreach($c as$v)if(!$line($v,4,true))return false;return true;}
    if($type==='MultiPolygon'){foreach($c as$poly){if(!is_array($poly)||!array_is_list($poly)||!$poly)return false;foreach($poly as$v)if(!$line($v,4,true))return false;}return true;}
    return false;
}

function radiusToNm(float $value, string $unit): ?float {if (!is_finite($value) || $value <= 0.0) return null;$nm=match (strtoupper(trim($unit))) {'NM'=>$value,'KM'=>$value/1.852,'M'=>$value/1852.0,default=>null};return $nm!==null&&$nm<=1000?$nm:null;}
function destinationPoint(float $lon, float $lat, float $bearingDeg, float $distanceNm): array {$earthRadiusNm = 3440.065;$distance = max(0.0,$distanceNm)/$earthRadiusNm;$bearing = deg2rad($bearingDeg);$lat1 = deg2rad($lat);$lon1 = deg2rad($lon);$sinLat2 = sin($lat1)*cos($distance) + cos($lat1)*sin($distance)*cos($bearing);$lat2 = asin(max(-1.0,min(1.0,$sinLat2)));$lon2 = $lon1 + atan2(sin($bearing)*sin($distance)*cos($lat1), cos($distance)-sin($lat1)*sin($lat2));return [round(normalizeLon(rad2deg($lon2)),6),round(rad2deg($lat2),6)];}
function initialBearing(array $from, array $to): float {$lat1 = deg2rad((float)$from[1]);$lat2 = deg2rad((float)$to[1]);$dLon = deg2rad((float)$to[0]-(float)$from[0]);$y = sin($dLon)*cos($lat2);$x = cos($lat1)*sin($lat2)-sin($lat1)*cos($lat2)*cos($dLon);return fmod(rad2deg(atan2($y,$x))+360.0,360.0);}
function corridorGeometry(array $coords, float $halfWidthNm): ?array {if (count($coords) < 2 || $halfWidthNm <= 0.0) return null;$polygons = [];for ($i=0; $i<count($coords)-1; $i++) {$a=$coords[$i]; $b=$coords[$i+1];if (abs((float)$a[0]-(float)$b[0])<0.000001 && abs((float)$a[1]-(float)$b[1])<0.000001) continue;$bearing=initialBearing($a,$b);$aLeft=destinationPoint((float)$a[0],(float)$a[1],$bearing-90,$halfWidthNm);$bLeft=destinationPoint((float)$b[0],(float)$b[1],$bearing-90,$halfWidthNm);$bRight=destinationPoint((float)$b[0],(float)$b[1],$bearing+90,$halfWidthNm);$aRight=destinationPoint((float)$a[0],(float)$a[1],$bearing+90,$halfWidthNm);$polygons[]=[[$aLeft,$bLeft,$bRight,$aRight,$aLeft]];}if (!$polygons) return null;return count($polygons)===1 ? ['type'=>'Polygon','coordinates'=>$polygons[0]] : ['type'=>'MultiPolygon','coordinates'=>$polygons];}
function qSubject(array $row): string {$q = strtoupper(trim((string)($row['selection_code'] ?? '')));return preg_match('/^Q([A-Z]{2})[A-Z]{2}$/',$q,$m) ? $m[1] : '';}
function semantic(array $row): array {$subject = qSubject($row);$class = match ($subject) {'RD','RP','RR','RT'=>'RESTRICTED_AIRSPACE','WY'=>'AERIAL_SURVEY','WE'=>'EXERCISE','WF'=>'AIR_REFUELING','WM'=>'FIRING','WU'=>'UAV_ACTIVITY','WG','WL','WP','WT'=>'AERIAL_SPORT_ACTIVITY','OB'=>'OBSTACLE','AC'=>'CONTROLLED_AIRSPACE','MR'=>'RUNWAY',default=>'OTHER'};$group = match ($subject) {'WG','WL','WP','WT'=>'AERIAL_SPORT','RD','RP','RR','RT'=>'RESTRICTED_AIRSPACE','WY'=>'AERIAL_SURVEY','WE','WF','WM','WU'=>'TRAINING_MILITARY',default=>'OTHER'};return ['q_subject'=>$subject,'semantic_class'=>$class,'display_group'=>$group];}
function spatialTextSegment(string $text): ?string {foreach (['/\bWI(?:THIN)?\s+AREA\b\s*:?\s*/i','/\bAREA\s+BOUNDED\s+BY\b\s*:?\s*/i','/\bBOUNDED\s+BY\b\s*:?\s*/i','/\bBOUNDARY\b\s*:?\s*/i','/\bLATERAL\s+LIMITS?\b\s*:?\s*/i','/\bAREA\b\s*:?\s*/i'] as $pattern) {if (!preg_match($pattern,$text,$m,PREG_OFFSET_CAPTURE)) continue;$offset=(int)$m[0][1]+strlen((string)$m[0][0]);$segment=substr($text,$offset);if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b)/i',$segment,$stop,PREG_OFFSET_CAPTURE)) $segment=substr($segment,0,(int)$stop[0][1]);return $segment;}return null;}
function explicitPolygon(array $row): ?array {$text=(string)($row['notam_text'] ?? '');$segment=spatialTextSegment($text);if ($segment===null) {$s=semantic($row);$area=in_array($s['semantic_class'],['RESTRICTED_AIRSPACE','AERIAL_SURVEY','EXERCISE','AIR_REFUELING','FIRING','UAV_ACTIVITY','AERIAL_SPORT_ACTIVITY','CONTROLLED_AIRSPACE'],true);if ($area) {foreach (['/\bWI\s*:\s*/i','/\bCOORDS?\s*:\s*/i','/\bCOORDINATES?\s*:\s*/i'] as $pattern) {if (!preg_match($pattern,$text,$m,PREG_OFFSET_CAPTURE)) continue;$offset=(int)$m[0][1]+strlen((string)$m[0][0]);$segment=substr($text,$offset);if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b|VERTICAL\s+LIMITS?\b)/i',$segment,$stop,PREG_OFFSET_CAPTURE)) $segment=substr($segment,0,(int)$stop[0][1]);break;}}}if ($segment===null) return null;$residue=preg_replace(coordinateRegex(),'',$segment);if(!is_string($residue)||preg_match('/\b(?:ARC|CLOCKWISE|COUNTERCLOCKWISE|EXCLUDING|EXCEPT|AREA\s+[0-9])\b/i',$residue))return null;$geometry=polygonFromCoordinates(extractCoordinates($segment,false));if ($geometry===null) return null;return ['geometry'=>$geometry,'source'=>'e-text-polygon','render_type'=>'AREA','confidence'=>'EXPLICIT','explicit_radius_nm'=>null];}
function explicitCircle(array $row): ?array {$text=strtoupper((string)($row['notam_text'] ?? ''));if($text==='')return null;$coord='(?:[0-9]{6}(?:\.[0-9]+)?[NS][ \t]*[0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][ \t]*[0-9]{5}(?:\.[0-9]+)?[EW]|[0-9]{2}\s+[0-9]{2}\s+[0-9]{2}(?:\.[0-9]+)?[NS]\s+[0-9]{3}\s+[0-9]{2}\s+[0-9]{2}(?:\.[0-9]+)?[EW])';if(preg_match('/\bWI(?:THIN)?\s+([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+OF\s+COORD(?:INATE)?S?\s*:?\s*('.$coord.')/is',$text,$m)){$radius=radiusToNm((float)$m[1],$m[2]);$point=parseCoordinate($m[3]);if($radius!==null&&$point!==null)return['geometry'=>circlePolygon((float)$point[0],(float)$point[1],$radius),'source'=>'e-text-circle','render_type'=>'CIRCLE','confidence'=>'EXPLICIT','explicit_radius_nm'=>round($radius,3)];}if(stripos($text,'RADIUS')===false)return null;$patterns=['/('.$coord.').{0,180}?\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\b/is','/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+RADIUS.{0,180}?('.$coord.')/is','/\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M).{0,180}?('.$coord.')/is'];foreach($patterns as$i=>$pattern){if(!preg_match($pattern,$text,$m))continue;if($i===0){$token=$m[1];$value=(float)$m[2];$unit=$m[3];}else{$value=(float)$m[1];$unit=$m[2];$token=$m[3];}$point=parseCoordinate($token);$radius=radiusToNm($value,$unit);if($point===null||$radius===null)continue;return['geometry'=>circlePolygon((float)$point[0],(float)$point[1],$radius),'source'=>'e-text-circle','render_type'=>'CIRCLE','confidence'=>'EXPLICIT','explicit_radius_nm'=>round($radius,3)];}return null;}
function explicitCorridor(array $row): ?array {$text=strtoupper((string)($row['notam_text'] ?? ''));if ($text==='' || stripos($text,'EITHER SIDE')===false) return null;if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+EITHER\s+SIDE\s+OF(?:\s+A)?\s+LINE/i',$text,$m)) return null;$width=radiusToNm((float)$m[1],$m[2]);if ($width===null) return null;$coords=extractCoordinates($text,false);$geometry=corridorGeometry($coords,$width);if ($geometry===null) return null;return ['geometry'=>$geometry,'source'=>'e-text-corridor','render_type'=>'CORRIDOR','confidence'=>'EXPLICIT','explicit_radius_nm'=>round($width,3)];}
function explicitGeometry(array $row): ?array {$polygon=explicitPolygon($row); if ($polygon!==null) return $polygon;$corridor=explicitCorridor($row); if ($corridor!==null) return $corridor;return explicitCircle($row);}
function normalizeGeometry(array $geometry): ?array {if(!validGeoJson($geometry))return null;$type=(string)($geometry['type'] ?? '');if ($type!=='GeometryCollection') return $type!=='' ? $geometry : null;$items=$geometry['geometries'] ?? null;if (!is_array($items) || !$items) return null;$points=[];$lines=[];$polygons=[];foreach ($items as $item) {if (!is_array($item)) continue;$g=normalizeGeometry($item); if ($g===null) continue;$t=(string)($g['type'] ?? ''); $c=$g['coordinates'] ?? null;if ($t==='Point' && is_array($c)) $points[]=$c;elseif ($t==='MultiPoint' && is_array($c)) foreach($c as $p) if(is_array($p))$points[]=$p;elseif ($t==='LineString' && is_array($c)) $lines[]=$c;elseif ($t==='MultiLineString' && is_array($c)) foreach($c as $line) if(is_array($line))$lines[]=$line;elseif ($t==='Polygon' && is_array($c)) $polygons[]=$c;elseif ($t==='MultiPolygon' && is_array($c)) foreach($c as $poly) if(is_array($poly))$polygons[]=$poly;}if ($polygons) return count($polygons)===1 ? ['type'=>'Polygon','coordinates'=>$polygons[0]] : ['type'=>'MultiPolygon','coordinates'=>$polygons];if ($lines) return count($lines)===1 ? ['type'=>'LineString','coordinates'=>$lines[0]] : ['type'=>'MultiLineString','coordinates'=>$lines];if ($points) return count($points)===1 ? ['type'=>'Point','coordinates'=>$points[0]] : ['type'=>'MultiPoint','coordinates'=>$points];return null;}
function pointRenderable(array $row, string $source, array $semantic): bool {if (($semantic['semantic_class'] ?? '')==='OBSTACLE') return true;$scope=strtoupper((string)($row['scope'] ?? ''));return in_array($source,['airport-location','faa-geometry','referenced-notam'],true) && str_contains($scope,'A');}
function category(array $semantic): string {return match ($semantic['semantic_class'] ?? 'OTHER') {'RESTRICTED_AIRSPACE','AERIAL_SURVEY','EXERCISE','AIR_REFUELING','FIRING','AERIAL_SPORT_ACTIVITY','CONTROLLED_AIRSPACE'=>'AIRSPACE','UAV_ACTIVITY'=>'UAV','OBSTACLE'=>'OBSTACLE','RUNWAY'=>'RWY',default=>'GENERAL'};}
function feature(array $row, ?array $activity = null): ?array {$geometry=is_array($row['geometry'] ?? null) ? $row['geometry'] : json_decode((string)($row['geometry'] ?? ''),true);if (!is_array($geometry)) return null;$geometry=normalizeGeometry($geometry); if ($geometry===null) return null;$source=(string)($row['geometry_source'] ?? 'faa-geometry');$semantic=semantic($row);$explicit=null;$type=(string)($geometry['type'] ?? '');if (in_array($type,['Point','MultiPoint'],true)) {$explicit=explicitGeometry($row);if ($explicit!==null) {$geometry=$explicit['geometry'];$source=$explicit['source'];$type=(string)$geometry['type'];} else {if (($semantic['semantic_class'] ?? '')==='OBSTACLE') {$coords=extractCoordinates((string)($row['notam_text'] ?? ''),true);if ($coords) {$geometry=['type'=>'Point','coordinates'=>[(float)$coords[0][0],(float)$coords[0][1]]];$source='e-text-point';$type='Point';}}if (!pointRenderable($row,$source,$semantic)) return null;}}$renderType=match($type) {'Polygon','MultiPolygon'=>$explicit['render_type'] ?? 'AREA','LineString','MultiLineString'=>'LINE','Point','MultiPoint'=>$source==='airport-location' ? 'ENTITY' : 'POINT',default=>'NONE'};$accuracy=match($source) {'faa-geometry'=>'authoritative FAA geometry','e-text-polygon'=>'explicit NOTAM boundary','e-text-circle'=>'explicit NOTAM circle','e-text-corridor'=>'explicit NOTAM corridor','e-text-point'=>'explicit NOTAM point','qline-coordinate'=>'coordinate point fallback','airport-location'=>'airport entity location','referenced-notam'=>'referenced FAA NOTAM geometry',default=>'derived'};return ['type'=>'Feature','id'=>'n-' . (string)$row['nms_id'],'geometry'=>$geometry,'properties'=>['layer'=>'notam','nms_id'=>(string)$row['nms_id'],'ident'=>ident($row),'classification'=>$row['classification'] ?? null,'location'=>$row['location'] ?? null,'icao_location'=>$row['icao_location'] ?? null,'selection_code'=>$row['selection_code'] ?? null,'effective_start'=>$row['effective_start'] ?? null,'effective_end'=>$row['effective_end'] ?? null,'effective_end_raw'=>$row['effective_end_raw'] ?? null,'estimated_end'=>estimatedEnd($row),'schedule'=>$row['schedule'] ?? null,'schedule_state'=>$activity['state'] ?? null,'schedule_parsed'=>$activity['parsed'] ?? null,'lower_limit'=>$row['lower_limit'] ?? null,'upper_limit'=>$row['upper_limit'] ?? null,'qline_radius_nm'=>isset($row['radius_nm']) && is_numeric((string)$row['radius_nm']) ? (float)$row['radius_nm'] : null,'explicit_radius_nm'=>$explicit['explicit_radius_nm'] ?? null,'q_subject'=>$semantic['q_subject'],'semantic_class'=>$semantic['semantic_class'],'display_group'=>$semantic['display_group'],'category'=>category($semantic),'map_render_type'=>$renderType,'geometry_source'=>$source,'geometry_accuracy'=>$accuracy]];}
function fingerprint(array $feature): string {$p=$feature['properties'] ?? [];return hash('sha256',json_encode([$p['ident'] ?? '',$p['effective_start'] ?? '',$p['effective_end_raw'] ?? ($p['effective_end'] ?? ''),$feature['geometry'] ?? null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
function bboxSql(float $west,float $south,float $east,float $north,array &$params): string {if ($west <= $east) {$params['bbox']=sprintf('POLYGON((%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F))',$west,$south,$east,$south,$east,$north,$west,$north,$west,$south);return 'MBRIntersects(n.geometry,ST_GeomFromText(:bbox))';}$params['bbox1']=sprintf('POLYGON((%.8F %.8F,180 %.8F,180 %.8F,%.8F %.8F,%.8F %.8F))',$west,$south,$south,$north,$west,$north,$west,$south);$params['bbox2']=sprintf('POLYGON((-180 %.8F,%.8F %.8F,%.8F %.8F,-180 %.8F,-180 %.8F))',$south,$east,$south,$east,$north,$north,$south);return '(MBRIntersects(n.geometry,ST_GeomFromText(:bbox1)) OR MBRIntersects(n.geometry,ST_GeomFromText(:bbox2)))';}
function validWhere(): string {return "n.source='FAA_NMS' AND n.environment='production' AND UPPER(COALESCE(n.notam_type,''))<>'C' AND (n.effective_start IS NULL OR n.effective_start<=:at_start) AND (UPPER(COALESCE(n.effective_end_raw,''))='PERM' OR n.effective_end IS NULL OR n.effective_end>=:at_end)";}
function addFeature(array $row,array &$features,array &$seenIds,array &$seenFeatures,DateTimeImmutable $at,array $replacementIndex,array $cancellationIndex,array &$mapStats): void {$id=(string)($row['nms_id'] ?? '');if ($id!=='' && (isset($seenIds[$id]) || isset($replacementIndex[$id]) || isset($cancellationIndex[$id]))) return;if (temporalState($row,$at,$replacementIndex,$cancellationIndex)!=='valid') return;$activity=scheduleActivity($row,$at);if ($activity['active'] === false) { $mapStats['outsideSchedule']++; return; }if ($activity['active'] === null) $mapStats['scheduleUnknown']++;$f=feature($row,$activity); if ($f===null) return;$hash=fingerprint($f); if(isset($seenFeatures[$hash]))return;if($id!=='')$seenIds[$id]=true; $seenFeatures[$hash]=true; $features[]=$f;}

function geometryDiagnosticIds(string $bucket): array {
    $path=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_nms'.DIRECTORY_SEPARATOR.'cron_state_production.json';
    if(!is_file($path))return[];$raw=@file_get_contents($path);$state=is_string($raw)?json_decode($raw,true):null;if(!is_array($state))return[];
    $items=$state['geometryDiagnostics'][$bucket]??[];if(!is_array($items))return[];$ids=[];
    foreach($items as$item){if(!is_array($item))continue;$id=trim((string)($item['nmsId']??''));if($id!==''&&strlen($id)<=150)$ids[$id]=true;}
    return array_keys($ids);
}
function geometryBounds(array $geometry): ?array {$minLon=INF;$maxLon=-INF;$minLat=INF;$maxLat=-INF;$walk=function(mixed$node)use(&$walk,&$minLon,&$maxLon,&$minLat,&$maxLat):void{if(!is_array($node))return;if(count($node)>=2&&is_numeric($node[0]??null)&&is_numeric($node[1]??null)){$lon=(float)$node[0];$lat=(float)$node[1];if(is_finite($lon)&&is_finite($lat)){$minLon=min($minLon,$lon);$maxLon=max($maxLon,$lon);$minLat=min($minLat,$lat);$maxLat=max($maxLat,$lat);}return;}foreach($node as$child)$walk($child);};$walk($geometry['coordinates']??null);return is_finite($minLon)&&is_finite($minLat)?[$minLon,$minLat,$maxLon,$maxLat]:null;}
function geometryInView(array $geometry,float$west,float$south,float$east,float$north):bool{$b=geometryBounds($geometry);if($b===null)return false;[$minLon,$minLat,$maxLon,$maxLat]=$b;if($maxLat<$south||$minLat>$north)return false;return$west<=$east?($maxLon>=$west&&$minLon<=$east):($maxLon>=$west||$minLon<=$east);}

function mapAction(PDO $pdo): never {
    foreach (['west','south','east','north'] as $key) if (!is_numeric($_GET[$key] ?? null)) out(400,['ok'=>false,'error'=>'bbox gerekli.']);
    $zoom=(int)boundedNumber('z',0,24,5);$west=normalizeLon(boundedNumber('west',-1080,1080)); $east=normalizeLon(boundedNumber('east',-1080,1080));$south=max(-85.0,min(85.0,boundedNumber('south',-90,90))); $north=max(-85.0,min(85.0,boundedNumber('north',-90,90)));if($south>$north)[$south,$north]=[$north,$south];$at=utc($_GET['at'] ?? '');$coverage=coverageInfo($pdo,$at);
    if($zoom<4) out(200,['ok'=>true,'source'=>'FAA NMS','displaySource'=>'FAA.GOV NOTAM SERVICE','atUtc'=>$at->format('c'),'coverage'=>$coverage,'data'=>['type'=>'FeatureCollection','features'=>[]],'counts'=>['notam'=>0],'truncated'=>false,'schedule'=>['outside'=>0,'unknown'=>0]]);
    header('Cache-Control: private, max-age=60, stale-while-revalidate=120');
    $time=$at->format('Y-m-d H:i:s');$features=[];$seenIds=[];$seenFeatures=[];$truncated=false;$replacementIndex=replacementIndex($pdo,$at);$cancellationIndex=cancellationIndex($pdo,$at);$mapStats=['outsideSchedule'=>0,'scheduleUnknown'=>0,'replaced'=>count($replacementIndex),'cancelled'=>count($cancellationIndex)];
    $params=['at_start'=>$time,'at_end'=>$time];$bbox=bboxSql($west,$south,$east,$north,$params);$sql='SELECT '.columns(true,true).",'faa-geometry' AS geometry_source FROM notams n WHERE ".validWhere()." AND n.geometry IS NOT NULL AND $bbox ORDER BY n.effective_start DESC LIMIT 5000";$stmt=$pdo->prepare($sql); $stmt->execute($params);while($row=$stmt->fetch()) addFeature($row,$features,$seenIds,$seenFeatures,$at,$replacementIndex,$cancellationIndex,$mapStats);if($stmt->rowCount()>=5000)$truncated=true;
    try {$ids=geometryDiagnosticIds('resolvedFallbackItems');if($ids){$holders=[];$qp=['at_start'=>$time,'at_end'=>$time];foreach(array_slice($ids,0,200)as$i=>$id){$key='gid'.$i;$holders[]=':'.$key;$qp[$key]=$id;}$q=$pdo->prepare('SELECT '.columns(true,false).',n.raw_json FROM notams n WHERE '.validWhere().' AND n.geometry IS NULL AND n.nms_id IN ('.implode(',',$holders).')');$q->execute($qp);while($row=$q->fetch()){$explicit=explicitGeometry($row);if($explicit===null||!geometryInView($explicit['geometry'],$west,$south,$east,$north))continue;$row['geometry']=$explicit['geometry'];$row['geometry_source']=$explicit['source'];addFeature($row,$features,$seenIds,$seenFeatures,$at,$replacementIndex,$cancellationIndex,$mapStats);}}}catch(Throwable$e){error_log('[notam-map-explicit] '.$e->getMessage());}
    try {$ids=geometryDiagnosticIds('resolvedReferenceItems');if($ids){$holders=[];$qp=['at_start'=>$time,'at_end'=>$time];foreach(array_slice($ids,0,200)as$i=>$id){$key='rid'.$i;$holders[]=':'.$key;$qp[$key]=$id;}$q=$pdo->prepare('SELECT '.columns(true,false).',n.raw_json FROM notams n WHERE '.validWhere().' AND n.geometry IS NULL AND n.nms_id IN ('.implode(',',$holders).')');$q->execute($qp);while($row=$q->fetch()){$geometry=referencedFdcGeometry($pdo,$row,'production',$at);if($geometry===null||!geometryInView($geometry,$west,$south,$east,$north))continue;$row['geometry']=$geometry;$row['geometry_source']='referenced-notam';addFeature($row,$features,$seenIds,$seenFeatures,$at,$replacementIndex,$cancellationIndex,$mapStats);}}}catch(Throwable$e){error_log('[notam-map-reference] '.$e->getMessage());}
    try {$lonWhere=$west<=$east ? 'lon BETWEEN :west AND :east' : '(lon>=:west OR lon<=:east)';$ap=$pdo->prepare("SELECT ident,lat,lon FROM nav_points WHERE kind='airport' AND lat BETWEEN :south AND :north AND $lonWhere LIMIT 2500");$ap->execute(['south'=>$south,'north'=>$north,'west'=>$west,'east'=>$east]);$airports=[];while($r=$ap->fetch()){$id=strtoupper(trim((string)$r['ident']));if($id!=='')$airports[$id]=['lon'=>(float)$r['lon'],'lat'=>(float)$r['lat']];}if($airports){foreach(array_chunk(array_keys($airports),400) as$chunk){$marks=implode(',',array_fill(0,count($chunk),'?'));$q=$pdo->prepare('SELECT '.columns(true,false).' FROM notams n WHERE '.str_replace([':at_start',':at_end'],['?','?'],validWhere())." AND n.geometry IS NULL AND (UPPER(COALESCE(n.icao_location,'')) IN ($marks) OR UPPER(COALESCE(n.location,'')) IN ($marks)) ORDER BY n.effective_start DESC LIMIT 5000");$values=[$time,$time,...$chunk,...$chunk]; $q->execute($values);while($row=$q->fetch()){$anchor=strtoupper(trim((string)($row['icao_location'] ?: $row['location']))); $point=$airports[$anchor] ?? null; if(!$point)continue;$row['geometry']=['type'=>'Point','coordinates'=>[$point['lon'],$point['lat']]]; $row['geometry_source']='airport-location';addFeature($row,$features,$seenIds,$seenFeatures,$at,$replacementIndex,$cancellationIndex,$mapStats);}}}} catch(Throwable $e){ error_log('[notam-map-airport] '.$e->getMessage()); }
    try {$coordToken="REGEXP_SUBSTR(UPPER(COALESCE(n.coordinates_raw,'')),'([0-9]{6}[NS][0-9]{7}[EW]|[0-9]{4}[NS][0-9]{5}[EW])')";$lonWhere=$west<=$east ? 'q.lon BETWEEN :west AND :east' : '(q.lon>=:west OR q.lon<=:east)';$sql='SELECT q.* FROM (SELECT p.*,CASE WHEN LENGTH(p.coord_token)=11 THEN (CAST(SUBSTRING(p.coord_token,1,2) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,3,2) AS DECIMAL(10,6))/60)*IF(SUBSTRING(p.coord_token,5,1)=\'S\',-1,1) WHEN LENGTH(p.coord_token)=15 THEN (CAST(SUBSTRING(p.coord_token,1,2) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,3,2) AS DECIMAL(10,6))/60+CAST(SUBSTRING(p.coord_token,5,2) AS DECIMAL(10,6))/3600)*IF(SUBSTRING(p.coord_token,7,1)=\'S\',-1,1) END lat,CASE WHEN LENGTH(p.coord_token)=11 THEN (CAST(SUBSTRING(p.coord_token,6,3) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,9,2) AS DECIMAL(10,6))/60)*IF(SUBSTRING(p.coord_token,11,1)=\'W\',-1,1) WHEN LENGTH(p.coord_token)=15 THEN (CAST(SUBSTRING(p.coord_token,8,3) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,11,2) AS DECIMAL(10,6))/60+CAST(SUBSTRING(p.coord_token,13,2) AS DECIMAL(10,6))/3600)*IF(SUBSTRING(p.coord_token,15,1)=\'W\',-1,1) END lon FROM (SELECT '.columns(true,false).','.$coordToken.' coord_token FROM notams n WHERE '.validWhere().' AND n.geometry IS NULL) p WHERE p.coord_token IS NOT NULL AND p.coord_token<>\'\') q WHERE q.lat BETWEEN :south AND :north AND '.$lonWhere.' ORDER BY q.effective_start DESC LIMIT 5000';$q=$pdo->prepare($sql);$q->execute(['at_start'=>$time,'at_end'=>$time,'south'=>$south,'north'=>$north,'west'=>$west,'east'=>$east]);while($row=$q->fetch()){$row['geometry']=['type'=>'Point','coordinates'=>[(float)$row['lon'],(float)$row['lat']]];$row['geometry_source']='qline-coordinate';addFeature($row,$features,$seenIds,$seenFeatures,$at,$replacementIndex,$cancellationIndex,$mapStats);}} catch(Throwable $e){ error_log('[notam-map-qline] '.$e->getMessage()); }
    if(count($features)>=5000)$truncated=true;
    out(200,['ok'=>true,'source'=>'FAA NMS','displaySource'=>'FAA.GOV NOTAM SERVICE','atUtc'=>$at->format('c'),'coverage'=>$coverage,'referenceResolution'=>referenceSummary($pdo,$at),'data'=>['type'=>'FeatureCollection','features'=>$features],'counts'=>['notam'=>count($features)],'total'=>count($features),'truncated'=>$truncated,'schedule'=>['outside'=>$mapStats['outsideSchedule'],'unknown'=>$mapStats['scheduleUnknown']],'temporal'=>['replaced'=>$mapStats['replaced'],'cancelled'=>$mapStats['cancelled']]]);
}

if (PHP_SAPI !== 'cli') {
try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {header('Allow: GET');out(405,['ok'=>false,'error'=>'HTTP method not allowed.']);}
    $pdo=db();$action=strtolower((string)($_GET['action'] ?? 'list'));
    if($action==='list')listAction($pdo);if($action==='filters')filtersAction($pdo);if($action==='detail')detailAction($pdo);if($action==='map')mapAction($pdo);if($action==='health')out(200,['ok'=>true,'resource'=>'notam','displaySource'=>'FAA.GOV NOTAM SERVICE','records'=>(int)$pdo->query("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment='production'")->fetchColumn()]);out(400,['ok'=>false,'error'=>'Bilinmeyen action.']);
} catch(Throwable $e) {error_log('[notam] ' . $e->getMessage());out(500,['ok'=>false,'error'=>'NOTAM isteği işlenemedi.']);}

}
