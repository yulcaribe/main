<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=600');

const REQUEST_BUDGET_SECONDS = 13;
$ycRequestStarted = microtime(true);

const YC_UA = 'YulCaribe-ModelWX/1.0 (+https://yulcaribe.com)';
const NOAA_FILTER = 'https://nomads.ncep.noaa.gov/cgi-bin/filter_gfs_0p25.pl';
const MAX_BINARY_BYTES = 12000000;

function jsonOut(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function binOut(string $body, array $meta=[]): never {
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.strlen($body));
    header('X-YC-Model-Source: '.($meta['source'] ?? 'NOAA/NCEP'));
    header('X-YC-Cycle: '.($meta['cycle'] ?? ''));
    header('X-YC-Forecast-Hour: '.($meta['fh'] ?? ''));
    header('X-YC-Level: '.($meta['level'] ?? ''));
    header('X-YC-Valid-UTC: '.($meta['validUtc'] ?? ''));
    header('X-YC-Requested-UTC: '.($meta['requestedUtc'] ?? ''));
    if (!empty($meta['records'])) header('X-YC-Records: '.substr(preg_replace('/[^A-Za-z0-9_.,;:+ -]/','',(string)$meta['records']),0,1500));
    echo $body;
    exit;
}
function cacheDir(): string {
    $d=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yulcaribe_modelwx_v1';
    if(!is_dir($d)) @mkdir($d,0700,true);
    return $d;
}
function cacheRead(string $key,int $ttl): ?string {
    $f=cacheDir().DIRECTORY_SEPARATOR.sha1($key).'.bin';
    if(!is_file($f)) return null;
    $age=time()-(int)@filemtime($f);
    if($age<0||$age>$ttl) return null;
    $b=@file_get_contents($f);
    return is_string($b)&&$b!==''?$b:null;
}
function cacheWrite(string $key,string $body): void {
    @file_put_contents(cacheDir().DIRECTORY_SEPARATOR.sha1($key).'.bin',$body,LOCK_EX);
}
function httpFetch(string $url, ?string $range=null, int $timeout=22, int $maxBytes=MAX_BINARY_BYTES): array {
    global $ycRequestStarted;
    $remaining=REQUEST_BUDGET_SECONDS-(microtime(true)-$ycRequestStarted);
    if($remaining<=0)throw new RuntimeException('NOAA toplam istek süresi doldu.');
    $ch=curl_init($url); $body='';
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT_MS=>min(6000,(int)($remaining*1000)),CURLOPT_TIMEOUT_MS=>(int)(min($timeout,$remaining)*1000),CURLOPT_USERAGENT=>YC_UA,
        CURLOPT_ENCODING=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: */*'],
        CURLOPT_WRITEFUNCTION=>function($ch,string $chunk) use (&$body,$maxBytes): int {
            if(strlen($body)+strlen($chunk)>$maxBytes) return 0;
            $body.=$chunk; return strlen($chunk);
        }
    ]);
    if($range!==null) curl_setopt($ch,CURLOPT_RANGE,$range);
    $ok=curl_exec($ch); $errno=curl_errno($ch); $err=curl_error($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $ctype=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['ok'=>$ok!==false&&$errno===0&&$status>=200&&$status<300,'status'=>$status,'body'=>$body,'error'=>$err,'ctype'=>$ctype];
}
function parseUtc(string $s): int {
    $t=$s!==''?strtotime($s.' UTC'):time();
    if($t===false)jsonOut(400,['ok'=>false,'error'=>'Geçersiz UTC zamanı.']);
    return $t;
}
function cycleFor(int $valid): array {
    $available=min($valid,time()-4*3600); $h=(int)gmdate('G',$available); $cycleHour=(int)(floor($h/6)*6);
    $cycle=gmmktime($cycleHour,0,0,(int)gmdate('n',$available),(int)gmdate('j',$available),(int)gmdate('Y',$available));
    $fh=(int)round(($valid-$cycle)/3600); $fh=max(0,min(120,$fh)); return [$cycle,$fh];
}
function cycleCandidates(int $valid): array {
    [$base,$fh]=cycleFor($valid); $out=[];
    for($i=0;$i<2;$i++){
        $cycle=$base-$i*21600; $f=(int)round(($valid-$cycle)/3600);
        if($f<0||$f>120)continue;
        $out[]=['epoch'=>$cycle,'date'=>gmdate('Ymd',$cycle),'cc'=>gmdate('H',$cycle),'fh'=>max(0,min(120,$f))];
    }
    return $out;
}
function pressureFromFL(int $fl): int {
    $hft=max(0,$fl*100.0); $hm=$hft*0.3048;
    $p=$hm<=11000 ? 1013.25*pow(1-2.25577e-5*$hm,5.25588) : 226.321*exp(-($hm-11000)/6341.62);
    // Primary GFS 0.25 pressure-grid levels exposed by NOMADS filter.
    // Do not request 225/175/125 mb here; those belong to the secondary pgrb2b dataset.
    $levels=[1000,975,950,925,900,850,800,750,700,650,600,550,500,450,400,350,300,250,200,150,100,70,50,40,30,20,15,10,7,5,3,2,1];
    $best=$levels[0]; $d=INF; foreach($levels as $v){$x=abs($p-$v);if($x<$d){$d=$x;$best=$v;}} return $best;
}
function bbox(): array {
    $left=(float)($_GET['left']??-180); $right=(float)($_GET['right']??180);
    $bottom=(float)($_GET['bottom']??-90); $top=(float)($_GET['top']??90);
    $left=max(-180,min(180,$left));$right=max(-180,min(180,$right));$bottom=max(-90,min(90,$bottom));$top=max(-90,min(90,$top));
    if($left>=$right){$left=-180;$right=180;} if($bottom>=$top){$bottom=-90;$top=90;} return [$left,$right,$bottom,$top];
}
function isGrib(string $b): bool { return strlen($b)>=16 && substr($b,0,4)==='GRIB'; }
function fetchGfs025(int $valid,int $pressure,array $bbox): array {
    [$left,$right,$bottom,$top]=$bbox; $last=null;
    foreach(cycleCandidates($valid) as $c){
        $fh=str_pad((string)$c['fh'],3,'0',STR_PAD_LEFT); $file="gfs.t{$c['cc']}z.pgrb2.0p25.f{$fh}";
        $q=['file'=>$file,'lev_'.$pressure.'_mb'=>'on','var_HGT'=>'on','var_TMP'=>'on','var_RH'=>'on','var_UGRD'=>'on','var_VGRD'=>'on',
            'subregion'=>'','leftlon'=>$left,'rightlon'=>$right,'toplat'=>$top,'bottomlat'=>$bottom,'dir'=>"/gfs.{$c['date']}/{$c['cc']}/atmos"];
        $url=NOAA_FILTER.'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986); $key='gfs025|'.$url;
        if($cached=cacheRead($key,1200)) return ['body'=>$cached,'meta'=>['source'=>'NOAA GFS 0.25','cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>$pressure.' hPa','validUtc'=>gmdate('c',$c['epoch']+$c['fh']*3600),'requestedUtc'=>gmdate('c',$valid)]];
        $r=httpFetch($url,null,12,8000000); $last=$r;
        if($r['ok']&&isGrib($r['body'])){cacheWrite($key,$r['body']);return ['body'=>$r['body'],'meta'=>['source'=>'NOAA GFS 0.25','cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>$pressure.' hPa','validUtc'=>gmdate('c',$c['epoch']+$c['fh']*3600),'requestedUtc'=>gmdate('c',$valid)]];}
    }
    throw new RuntimeException('NOAA GFS 0.25 GRIB Filter yanıt vermedi'.($last?(' (HTTP '.$last['status'].')'):''));
}
// AWF open distribution was withdrawn on 2024-01-17 (NOAA SCN 23-111).
// Do not probe retired URLs on every briefing or relabel GFS diagnostics as EDR/icing.
const AWF_NOTICE = 'https://www.weather.gov/media/notification/pdf_2023_24/scn23-111_wafs_products_change.pdf';
const WIFS_URL = 'https://aviationweather.gov/wifs/';
$action=strtolower(trim((string)($_GET['action']??'status')));
if($action==='aviation025'||$action==='wafs025')jsonOut(410,[
    'ok'=>false,'source'=>'aviation025','code'=>'PUBLIC_FEED_RETIRED',
    'error'=>'NOAA AWF açık dağıtımı 17 Ocak 2024 tarihinde kaldırıldı. EDR / CAT / MWT / CB verisi için yetkili WIFS veya başka doğrulanmış kaynak gerekli.',
    'noticeUrl'=>AWF_NOTICE,'accessUrl'=>WIFS_URL
]);
if($action==='wafs125')jsonOut(503,[
    'ok'=>false,'source'=>'wafs125','code'=>'REFERENCE_UNAVAILABLE',
    'error'=>'Legacy WAFS 1.25° için doğrulanmış güncel public dosya/inventory bağlantısı yok. Ana GFS bundan bağımsız çalışır.'
]);
if(!in_array($action,['status','gfs025'],true))jsonOut(400,['ok'=>false,'error'=>'Bilinmeyen action.']);
$fl=max(50,min(600,(int)($_GET['fl']??360)));
$valid=parseUtc(trim((string)($_GET['valid']??'')));$pressure=pressureFromFL($fl);$bb=bbox();[$cycle,$fh]=cycleFor($valid);
if($action==='status')jsonOut(200,['ok'=>true,'validUtc'=>gmdate('c',$valid),'cycleUtc'=>gmdate('c',$cycle),'forecastHour'=>$fh,'cruiseFL'=>$fl,'nearestPressureMb'=>$pressure,'levelMethod'=>'nearest primary GFS pressure level; not exact flight level','bbox'=>['left'=>$bb[0],'right'=>$bb[1],'bottom'=>$bb[2],'top'=>$bb[3]],'products'=>[
    ['id'=>'gfs025','label'=>'NOAA GFS 0.25°','purpose'=>'upper wind / temperature / RH / height','mode'=>'NOMADS GRIB Filter','availability'=>'request_required'],
    ['id'=>'aviation025','label'=>'NOAA Aviation GFS 0.25°','availability'=>'PUBLIC_FEED_RETIRED','noticeUrl'=>AWF_NOTICE,'accessUrl'=>WIFS_URL],
    ['id'=>'wafs125','label'=>'Legacy WAFS 1.25°','availability'=>'REFERENCE_UNAVAILABLE']
],'note'=>'MODEL GUIDANCE. Eksik aviation hazard verisi, tehlike olmadığı anlamına gelmez.']);
if(!function_exists('curl_init'))jsonOut(500,['ok'=>false,'error'=>'PHP cURL aktif değil.']);
if($valid<time()-7*86400||!cycleCandidates($valid))jsonOut(400,['ok'=>false,'error'=>'Model zamanı son 7 gün ile yaklaşık 4 gün sonrası arasında olmalı.']);
try{
    $r=fetchGfs025($valid,$pressure,$bb);binOut($r['body'],$r['meta']);
}catch(Throwable $e){jsonOut(502,['ok'=>false,'source'=>$action,'error'=>$e->getMessage(),'validUtc'=>gmdate('c',$valid),'cruiseFL'=>$fl,'pressureMb'=>$pressure]);}
