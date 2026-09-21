<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=600');

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
    $ch=curl_init($url); $body='';
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>$timeout,CURLOPT_USERAGENT=>YC_UA,
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
    $t=$s!==''?strtotime($s.' UTC'):time(); return $t===false?time():$t;
}
function cycleFor(int $valid): array {
    $available=min($valid,time()-4*3600); $h=(int)gmdate('G',$available); $cycleHour=(int)(floor($h/6)*6);
    $cycle=gmmktime($cycleHour,0,0,(int)gmdate('n',$available),(int)gmdate('j',$available),(int)gmdate('Y',$available));
    $fh=(int)round(($valid-$cycle)/10800)*3; $fh=max(0,min(120,$fh)); return [$cycle,$fh];
}
function cycleCandidates(int $valid): array {
    [$base,$fh]=cycleFor($valid); $out=[];
    for($i=0;$i<4;$i++){
        $cycle=$base-$i*21600; $f=(int)round(($valid-$cycle)/10800)*3;
        $out[]=['epoch'=>$cycle,'date'=>gmdate('Ymd',$cycle),'cc'=>gmdate('H',$cycle),'fh'=>max(0,min(120,$f))];
    }
    return $out;
}
function pressureFromFL(int $fl): int {
    $hft=max(0,$fl*100.0); $hm=$hft*0.3048;
    $p=$hm<=11000 ? 1013.25*pow(1-2.25577e-5*$hm,5.25588) : 226.321*exp(-($hm-11000)/6341.62);
    $levels=[1000,975,950,925,900,850,800,750,700,650,600,550,500,450,400,350,300,250,225,200,175,150,125,100,70,50];
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
        if($cached=cacheRead($key,1200)) return ['body'=>$cached,'meta'=>['source'=>'NOAA GFS 0.25','cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>$pressure.' mb']];
        $r=httpFetch($url,null,24,8000000); $last=$r;
        if($r['ok']&&isGrib($r['body'])){cacheWrite($key,$r['body']);return ['body'=>$r['body'],'meta'=>['source'=>'NOAA GFS 0.25','cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>$pressure.' mb']];}
    }
    throw new RuntimeException('NOAA GFS 0.25 GRIB Filter yanıt vermedi'.($last?(' (HTTP '.$last['status'].')'):''));
}
function baseCandidates(string $date,string $cc,string $filename): array {
    $rel="gfs.{$date}/{$cc}/atmos/{$filename}";
    return ['https://ftp.ncep.noaa.gov/data/nccf/com/gfs/prod/'.$rel,'https://www.ftp.ncep.noaa.gov/data/nccf/com/gfs/prod/'.$rel,'https://nomads.ncep.noaa.gov/pub/data/nccf/com/gfs/prod/'.$rel];
}
function idxEntries(string $txt): array {
    $rows=[]; foreach(preg_split('/\r?\n/',$txt)?:[] as $line){if(!preg_match('/^(\d+):(\d+):(.*)$/',$line,$m))continue;$rows[]=['n'=>(int)$m[1],'offset'=>(int)$m[2],'rest'=>$m[3],'line'=>$line];} return $rows;
}
function linePressure(string $line): ?int { return preg_match('/:(\d{2,4}) mb:/i',$line,$m)?(int)$m[1]:null; }
function lineFL(string $line): ?int {
    if(preg_match('/FL\s?(\d{2,3})/i',$line,$m))return (int)$m[1];
    if(preg_match('/(\d{2,3})00 ft/i',$line,$m))return (int)$m[1]; return null;
}
function selectWafs025(array $entries,int $fl,int $pressure): array {
    $wanted=['ICESEV','ICSEV','EDPARM','CATEDR','MWTURB','CBHE','ICAHT']; $groups=[];
    foreach($entries as $i=>$e){
        $var=null; foreach($wanted as $w){if(stripos(':'.$e['rest'].':',':'.$w.':')!==false){$var=$w;break;}} if(!$var)continue;
        $score=5000; $lf=lineFL($e['line']); $lp=linePressure($e['line']);
        if($lf!==null)$score=abs($lf-$fl); elseif($lp!==null)$score=abs($lp-$pressure)*0.5; elseif(in_array($var,['CBHE','ICAHT'],true))$score=0;
        $groups[$var][]=[$score,$i,$e];
    }
    $selected=[]; foreach($groups as $var=>$rows){usort($rows,fn($a,$b)=>$a[0]<=>$b[0]);$take=$var==='ICAHT'?2:1;for($j=0;$j<min($take,count($rows));$j++)$selected[]=$rows[$j][2];}
    usort($selected,fn($a,$b)=>$a['offset']<=>$b['offset']); return array_slice($selected,0,9);
}
function selectWafs125(array $entries,int $pressure): array {
    $vars=['TMP','UGRD','VGRD','HGT','RH'];$best=[];
    foreach($entries as $e){foreach($vars as $v){if(stripos(':'.$e['rest'].':',':'.$v.':')===false)continue;$p=linePressure($e['line']);if($p===null)continue;$s=abs($p-$pressure);if(!isset($best[$v])||$s<$best[$v][0])$best[$v]=[$s,$e];}}
    $out=[];foreach($best as $b)$out[]=$b[1];usort($out,fn($a,$b)=>$a['offset']<=>$b['offset']);return $out;
}
function fetchRanges(string $base,array $all,array $sel): ?string {
    if(!$sel)return null;$pos=[];foreach($all as $i=>$e)$pos[$e['offset']]=$i;$out='';
    foreach($sel as $e){
        $i=$pos[$e['offset']]??null;if($i===null)continue;$start=$e['offset'];$end=isset($all[$i+1])?$all[$i+1]['offset']-1:null;$range=$end!==null?($start.'-'.$end):($start.'-');
        $r=httpFetch($base,$range,20,5000000);if(!$r['ok']||!isGrib($r['body']))return null;if($r['status']===200&&$start>0)return null;$out.=$r['body'];if(strlen($out)>MAX_BINARY_BYTES)return null;
    }
    return $out!==''?$out:null;
}
function tryIndexedProduct(array $urls, callable $selector): ?array {
    foreach($urls as $u){
        $idx=httpFetch($u.'.idx',null,10,1000000);if(!$idx['ok']||trim($idx['body'])==='')continue;$all=idxEntries($idx['body']);if(!$all)continue;$sel=$selector($all);if(!$sel)continue;
        $cacheKey='range|'.$u.'|'.sha1(implode('|',array_column($sel,'line')));
        if($cached=cacheRead($cacheKey,1800))return ['body'=>$cached,'url'=>$u,'records'=>implode(';',array_map(fn($e)=>$e['rest'],$sel))];
        $body=fetchRanges($u,$all,$sel);if($body!==null){cacheWrite($cacheKey,$body);return ['body'=>$body,'url'=>$u,'records'=>implode(';',array_map(fn($e)=>$e['rest'],$sel))];}
    }
    return null;
}
function fetchWafs025(int $valid,int $fl,int $pressure): array {
    foreach(cycleCandidates($valid) as $c){
        $fh=str_pad((string)$c['fh'],3,'0',STR_PAD_LEFT);$name="gfs.t{$c['cc']}z.wafs_0p25.f{$fh}.grib2";
        $r=tryIndexedProduct(baseCandidates($c['date'],$c['cc'],$name),fn($rows)=>selectWafs025($rows,$fl,$pressure));
        if($r)return ['body'=>$r['body'],'meta'=>['source'=>'NOAA GFS/WAFS 0.25 aviation','cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>'FL'.$fl,'records'=>$r['records']]];
    }
    throw new RuntimeException('Güncel NOAA WAFS 0.25 aviation dosyası public HTTP kaynaklarında bulunamadı.');
}
function fetchWafs125(int $valid,int $pressure): array {
    foreach(cycleCandidates($valid) as $c){
        $fh=str_pad((string)$c['fh'],3,'0',STR_PAD_LEFT);
        foreach(['40','44'] as $grid){
            $name="wafsgfs{$grid}.t{$c['cc']}z.gribf{$fh}.grib2";$r=tryIndexedProduct(baseCandidates($c['date'],$c['cc'],$name),fn($rows)=>selectWafs125($rows,$pressure));
            if($r)return ['body'=>$r['body'],'meta'=>['source'=>'NOAA legacy WAFS 1.25 grid '.$grid,'cycle'=>gmdate('Y-m-d H\Z',$c['epoch']),'fh'=>$c['fh'],'level'=>$pressure.' mb','records'=>$r['records']]];
        }
    }
    throw new RuntimeException('NOAA legacy WAFS 1.25 upper-air dosyası public HTTP kaynaklarında bulunamadı.');
}

if(!function_exists('curl_init'))jsonOut(500,['ok'=>false,'error'=>'PHP cURL aktif değil.']);
$action=strtolower(trim((string)($_GET['action']??'status')));$fl=max(50,min(600,(int)($_GET['fl']??360)));$valid=parseUtc(trim((string)($_GET['valid']??'')));$pressure=pressureFromFL($fl);$bb=bbox();[$cycle,$fh]=cycleFor($valid);
if($action==='status')jsonOut(200,['ok'=>true,'validUtc'=>gmdate('c',$valid),'cycleUtc'=>gmdate('c',$cycle),'forecastHour'=>$fh,'cruiseFL'=>$fl,'nearestPressureMb'=>$pressure,'bbox'=>['left'=>$bb[0],'right'=>$bb[1],'bottom'=>$bb[2],'top'=>$bb[3]],'products'=>[
    ['id'=>'gfs025','label'=>'NOAA GFS 0.25°','purpose'=>'upper wind / temperature / RH / height','mode'=>'NOMADS GRIB Filter'],
    ['id'=>'wafs025','label'=>'NOAA WAFS 0.25° aviation','purpose'=>'icing / turbulence / CB when present in public feed','mode'=>'indexed GRIB2'],
    ['id'=>'wafs125','label'=>'NOAA legacy WAFS 1.25°','purpose'=>'upper-air comparison','mode'=>'indexed GRIB2']
],'note'=>'Old WAFS_blended 1.25 hazard product was retired; current hazard target is WAFS 0.25.']);
try{
    if($action==='gfs025'){$r=fetchGfs025($valid,$pressure,$bb);binOut($r['body'],$r['meta']);}
    if($action==='wafs025'){$r=fetchWafs025($valid,$fl,$pressure);binOut($r['body'],$r['meta']);}
    if($action==='wafs125'){$r=fetchWafs125($valid,$pressure);binOut($r['body'],$r['meta']);}
    jsonOut(400,['ok'=>false,'error'=>'Bilinmeyen action.']);
}catch(Throwable $e){jsonOut(502,['ok'=>false,'source'=>$action,'error'=>$e->getMessage(),'validUtc'=>gmdate('c',$valid),'cruiseFL'=>$fl,'pressureMb'=>$pressure]);}
