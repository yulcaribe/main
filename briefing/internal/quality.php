<?php
declare(strict_types=1);

/** Shared data rules. These functions do not query or mutate application state on load. */
const YC_BRIEF_REVISION = '20260926.2';

function ycBriefCacheRead(string $key, int $ttl): ?array {
    $file=sys_get_temp_dir().'/yc_brief_'.YC_BRIEF_REVISION.'_'.sha1($key).'.json';
    if (!is_file($file) || time()-(int)filemtime($file)>$ttl) return null;
    $data=json_decode((string)@file_get_contents($file),true);
    return is_array($data)?$data:null;
}
function ycBriefCacheWrite(string $key,array $value): void {
    $file=sys_get_temp_dir().'/yc_brief_'.YC_BRIEF_REVISION.'_'.sha1($key).'.json';
    $tmp=@tempnam(sys_get_temp_dir(),'yc_brief_');
    if ($tmp===false) return;
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json!==false && @file_put_contents($tmp,$json,LOCK_EX)!==false) @rename($tmp,$file);
    if (is_file($tmp)) @unlink($tmp);
}
function ycBriefNavDetails(array $row): array {
    $extra=json_decode((string)($row['extra_json']??''),true);
    $extra=is_array($extra)?$extra:[]; $components=[];
    foreach ($extra as $key=>$value) {
        if (!preg_match('/^c(\d+)type$/',$key,$m)) continue;
        $prefix='c'.$m[1]; $type=strtolower((string)$value);
        $hz=$extra[$prefix.'frequency']??$extra[$prefix.'pairedfreq']??null;
        $frequency=is_numeric($hz)&&$hz>0?(float)$hz:null;
        $channel=$extra[$prefix.'channel']??null;
        $components[]=[
            'type'=>$type,'name'=>$extra[$prefix.'name']??null,
            'frequencyHz'=>$frequency,
            'frequencyText'=>$frequency===null?null:($type==='ndb'?rtrim(rtrim(number_format($frequency/1000,3,'.',''),'0'),'.').' kHz':number_format($frequency/1000000,3,'.','').' MHz'),
            'channel'=>$channel!==null?(string)$channel.(string)($extra[$prefix.'channelsuffix']??''):null,
            'sourceStatus'=>$extra[$prefix.'oprstat']??null
        ];
    }
    return ['name'=>$row['name']??($components[0]['name']??null),
        'frequency'=>$row['frequency_text']??null,'channel'=>$row['channel']??null,
        'sourceStatus'=>$row['provider_status']??null,'components'=>$components];
}
function ycBriefCumulative(array $route): array {
    $out=[0.0];
    for($i=1;$i<count($route);$i++) $out[]=$out[$i-1]+haversineNm((float)$route[$i-1][0],(float)$route[$i-1][1],(float)$route[$i][0],(float)$route[$i][1]);
    return $out;
}
function ycBriefResample(array $route,int $count): array {
    if(count($route)<2) return $route;
    $cum=ycBriefCumulative($route);$total=end($cum);$out=[];$leg=0;$count=max(2,$count);
    for($i=0;$i<$count;$i++) {
        $target=$total*$i/($count-1);
        while($leg<count($route)-2&&$cum[$leg+1]<$target)$leg++;
        $span=$cum[$leg+1]-$cum[$leg];$f=$span>0?($target-$cum[$leg])/$span:0;
        $a=$route[$leg];$b=$route[$leg+1];
        $p1=deg2rad($a[0]);$l1=deg2rad($a[1]);$p2=deg2rad($b[0]);$l2=deg2rad($b[1]);
        $d=haversineNm($a[0],$a[1],$b[0],$b[1])/3440.065;
        if($d<1e-9){$out[]=$a;continue;}
        $aa=sin((1-$f)*$d)/sin($d);$bb=sin($f*$d)/sin($d);
        $x=$aa*cos($p1)*cos($l1)+$bb*cos($p2)*cos($l2);$y=$aa*cos($p1)*sin($l1)+$bb*cos($p2)*sin($l2);$z=$aa*sin($p1)+$bb*sin($p2);
        $out[]=[rad2deg(atan2($z,hypot($x,$y))),rad2deg(atan2($y,$x))];
    }
    return $out;
}
/** Keep every source turn while adding intermediate points on long legs. */
function ycBriefDensify(array $route,float $spacingNm=10): array {
    if(count($route)<2)return $route;
    $cum=ycBriefCumulative($route);$spacingNm=max($spacingNm,(float)end($cum)/1600);$out=[$route[0]];
    for($i=1;$i<count($route);$i++){
        $count=max(2,(int)ceil(($cum[$i]-$cum[$i-1])/$spacingNm)+1);
        foreach(array_slice(ycBriefResample([$route[$i-1],$route[$i]],$count),1) as $point)$out[]=$point;
    }
    return $out;
}
function ycBriefQuality(string $mode,array $input,bool $automatic): array {
    $missing=count($input['unresolved']??[])+count($input['pendingNavdata']??[])+count($input['unknown']??[]);
    $fallback=count(array_filter($input['navdataResolved']??[],static fn($r)=>!empty($r['directionFallback'])));
    $state=$mode==='great_circle'?'fallback':($missing||$fallback||($input['engine']??'')!=='mariadb_navdata'?'partial':'resolved');
    return ['state'=>$state,'origin'=>$automatic?'automatic':($input['raw']?'user':'direct'),
        'missingCount'=>$missing,'directionFallbacks'=>$fallback,'operationallyValidated'=>false,
        'terminalProceduresComplete'=>count(array_filter($input['navdataResolved']??[],static fn($r)=>($r['type']??'')==='sid'))>0&&count(array_filter($input['navdataResolved']??[],static fn($r)=>($r['type']??'')==='star'))>0];
}
function ycBriefAvailability(PDO $pdo,array $ids): array {
    $out=[];$ids=array_values(array_unique(array_map('intval',$ids)));
    foreach(array_chunk($ids,800) as $chunk){
        $s=$pdo->prepare('SELECT membership_id,lower_text,upper_text,upper_unlimited,status,cdr,forward,backward FROM nav_route_availability WHERE membership_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');
        $s->execute($chunk);while($r=$s->fetch(PDO::FETCH_ASSOC))$out[(int)$r['membership_id']][]=$r;
    }
    return $out;
}
/** Source forward=true describes the forward direction; false describes reverse. */
function ycBriefDirectionState(array $rows,int $fl,bool $forward): string {
    $states=[];
    foreach($rows as $r){
        if($r['forward']===null)continue;
        if(((int)$r['forward']===1)!==$forward)continue;
        if(!ycArLevelAllowed($r,$fl))continue;
        $states[]=strtolower((string)($r['status']??''));
    }
    if(in_array('closed',$states,true))return 'closed';
    if(in_array('conditional',$states,true))return 'conditional';
    return in_array('open',$states,true)?'open':'unknown';
}

function ycBriefNavContext(?PDO $pdo,string $from,string $to): array {
    $context=['available'=>false,'cycle'=>null,'validityKnown'=>false,'chartDocumentsAvailable'=>false,'terminals'=>[]];
    if(!$pdo)return $context;
    $key='nav-context|'.$from.'|'.$to;
    if($cached=ycBriefCacheRead($key,300))return $cached;
    try{
        $metadata=$pdo->query('SELECT `key`,`value` FROM nav_metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        $context['generatedAt']=$metadata['generated_at']??null;
        foreach([['airport'=>$from,'type'=>'sid'],['airport'=>$to,'type'=>'star']] as $terminal){
            $stmt=$pdo->prepare('SELECT DISTINCT r.id,r.ident,r.type FROM nav_routes r JOIN nav_route_memberships m ON m.route_id=r.id JOIN nav_route_segments s ON s.id=m.segment_id WHERE r.type=? AND (s.from_ident=? OR s.to_ident=?) ORDER BY r.ident LIMIT 251');
            $stmt->execute([$terminal['type'],$terminal['airport'],$terminal['airport']]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $context['terminals'][]=['airport'=>$terminal['airport'],'type'=>$terminal['type'],'procedures'=>array_slice($rows,0,250),'truncated'=>count($rows)>250,'runwayAssociationKnown'=>false];
        }
        $context['available']=true;ycBriefCacheWrite($key,$context);return $context;
    }catch(Throwable $e){error_log('[briefing-nav-context] metadata unavailable');return $context;}
}
