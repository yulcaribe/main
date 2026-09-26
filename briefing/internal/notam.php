<?php
declare(strict_types=1);

/** Route/time/level-aware local FAA NMS NOTAM enrichment. */
require_once dirname(__DIR__, 2) . '/notam/core.php';

function ycBn2Utc(?string $raw,?DateTimeImmutable $fallback=null): DateTimeImmutable {
    $utc=new DateTimeZone('UTC');$raw=trim((string)$raw);
    if($raw==='')return $fallback??new DateTimeImmutable('now',$utc);
    try{return (new DateTimeImmutable($raw,$utc))->setTimezone($utc);}catch(Throwable){return $fallback??new DateTimeImmutable('now',$utc);}
}
function ycBn2Orient(float $ax,float $ay,float $bx,float $by,float $cx,float $cy): float{return($bx-$ax)*($cy-$ay)-($by-$ay)*($cx-$ax);}
function ycBn2SegmentsIntersect(array $a,array $b,array $c,array $d): bool{
    $o1=ycBn2Orient((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0],(float)$c[1],(float)$c[0]);
    $o2=ycBn2Orient((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0],(float)$d[1],(float)$d[0]);
    $o3=ycBn2Orient((float)$c[1],(float)$c[0],(float)$d[1],(float)$d[0],(float)$a[1],(float)$a[0]);
    $o4=ycBn2Orient((float)$c[1],(float)$c[0],(float)$d[1],(float)$d[0],(float)$b[1],(float)$b[0]);
    return (($o1>0&&$o2<0)||($o1<0&&$o2>0))&&(($o3>0&&$o4<0)||($o3<0&&$o4>0));
}
function ycBn2PointSegmentNm(float $lat,float $lon,float $lat1,float $lon1,float $lat2,float $lon2): float{
    $lat0=deg2rad(($lat+$lat1+$lat2)/3.0);$x=($lon-$lon1)*cos($lat0)*60.0;$y=($lat-$lat1)*60.0;$dx=($lon2-$lon1)*cos($lat0)*60.0;$dy=($lat2-$lat1)*60.0;$den=$dx*$dx+$dy*$dy;
    if($den<1e-12)return sqrt($x*$x+$y*$y);$t=max(0.0,min(1.0,($x*$dx+$y*$dy)/$den));return sqrt(($x-$t*$dx)**2+($y-$t*$dy)**2);
}
function ycBn2PointInRing(float $lat,float $lon,array $ring): bool{
    $inside=false;$n=count($ring);if($n<3)return false;
    for($i=0,$j=$n-1;$i<$n;$j=$i++){$xi=(float)($ring[$i][0]??0);$yi=(float)($ring[$i][1]??0);$xj=(float)($ring[$j][0]??0);$yj=(float)($ring[$j][1]??0);$cross=(($yi>$lat)!==($yj>$lat));if(!$cross)continue;$x=($xj-$xi)*($lat-$yi)/(($yj-$yi)?:1e-12)+$xi;if($lon<$x)$inside=!$inside;}return $inside;
}
function ycBn2LineDistance(array $route,array $line): float{
    if(count($route)<2||count($line)<1)return INF;$best=INF;
    if(count($line)===1&&is_array($line[0])&&count($line[0])>=2){$lon=(float)$line[0][0];$lat=(float)$line[0][1];for($i=0;$i<count($route)-1;$i++){$a=$route[$i];$b=$route[$i+1];$best=min($best,ycBn2PointSegmentNm($lat,$lon,(float)$a[0],(float)$a[1],(float)$b[0],(float)$b[1]));}return $best;}
    for($i=0;$i<count($route)-1;$i++){$a=$route[$i];$b=$route[$i+1];for($j=0;$j<count($line)-1;$j++){$p=$line[$j]??null;$q=$line[$j+1]??null;if(!is_array($p)||!is_array($q)||count($p)<2||count($q)<2)continue;$c=[(float)$p[1],(float)$p[0]];$d=[(float)$q[1],(float)$q[0]];if(ycBn2SegmentsIntersect($a,$b,$c,$d))return 0.0;$best=min($best,ycBn2PointSegmentNm($c[0],$c[1],(float)$a[0],(float)$a[1],(float)$b[0],(float)$b[1]),ycBn2PointSegmentNm($d[0],$d[1],(float)$a[0],(float)$a[1],(float)$b[0],(float)$b[1]),ycBn2PointSegmentNm((float)$a[0],(float)$a[1],$c[0],$c[1],$d[0],$d[1]),ycBn2PointSegmentNm((float)$b[0],(float)$b[1],$c[0],$c[1],$d[0],$d[1]));}}
    return $best;
}
function ycBn2PolygonDistance(array $route,array $rings): float{$outer=$rings[0]??null;if(!is_array($outer)||count($outer)<3)return INF;foreach($route as $p)if(is_array($p)&&count($p)>=2&&ycBn2PointInRing((float)$p[0],(float)$p[1],$outer))return 0.0;return ycBn2LineDistance($route,$outer);}
function ycBn2GeometryDistance(array $route,?array $geometry): float{
    if(!$geometry||count($route)<2)return INF;$t=(string)($geometry['type']??'');$c=$geometry['coordinates']??null;if(!is_array($c))return INF;
    if($t==='Point')return ycBn2LineDistance($route,[$c]);
    if($t==='MultiPoint'){$best=INF;foreach($c as $point)$best=min($best,ycBn2LineDistance($route,[$point]));return $best;}
    if($t==='LineString')return ycBn2LineDistance($route,$c);
    if($t==='Polygon')return ycBn2PolygonDistance($route,$c);
    $best=INF;
    if($t==='MultiLineString'){foreach($c as $line)if(is_array($line))$best=min($best,ycBn2LineDistance($route,$line));}
    elseif($t==='MultiPolygon'){foreach($c as $poly)if(is_array($poly))$best=min($best,ycBn2PolygonDistance($route,$poly));}
    return $best;
}
function ycBn2Semantic(?string $selectionCode): string{
    $q=strtoupper(trim((string)$selectionCode));$subject=preg_match('/^Q([A-Z]{2})[A-Z]{2}$/',$q,$m)?$m[1]:'';
    return match($subject){'MR'=>'RUNWAY','MX'=>'TAXIWAY','MN'=>'APRON','FA','AF','AC'=>'AIRSPACE','RD','RP','RR','RT'=>'RESTRICTED_AIRSPACE','WY'=>'AERIAL_SURVEY','WE','WF','WM'=>'MILITARY_ACTIVITY','WU'=>'UAV_ACTIVITY','OB'=>'OBSTACLE',default=>'OTHER'};
}
function ycBn2Priority(string $semantic,string $text): string{
    $u=strtoupper($text);
    if(in_array($semantic,['RUNWAY','AIRSPACE','RESTRICTED_AIRSPACE'],true)||preg_match('/\bAD\s+CLSD\b|\b(RWY|ILS|LOC|VOR|NDB|DME|RNAV|RNP|SID|STAR|APCH|APPROACH|OCA|OCH)\b/',$u))return 'high';
    if(in_array($semantic,['TAXIWAY','APRON','OBSTACLE','MILITARY_ACTIVITY','UAV_ACTIVITY'],true)||preg_match('/\b(TWY|APRON|PAPI|LIGHT|LIGHTS|BARRIER|HELIPAD|FUEL|CRANE|OBSTACLE|CLSD|CLOSED|U\/S|UNSERVICEABLE|SUSPENDED|NOT AVBL)\b/',$u))return 'medium';
    return 'info';
}
function ycBn2RouteRefs(array $payload,array $query): array {
    if(($payload['routeMode']??'')==='great_circle')return [];
    $refs=[];
    foreach(($payload['routeEngine']['navdataResolved']??[]) as $r){
        $id=strtoupper(trim((string)($r['id']??'')));
        if(preg_match('/^[A-Z0-9]{2,12}$/',$id))$refs[$id]=true;
    }
    return array_keys($refs);
}

function ycBn2Bbox(array $route,float $pad=1.5): array{$lats=[];$lons=[];foreach($route as $p){if(!is_array($p)||count($p)<2)continue;$lats[]=(float)$p[0];$lons[]=(float)$p[1];}if(!$lats||!$lons)return[-180,-90,180,90];$s=max(-90,min($lats)-$pad);$n=min(90,max($lats)+$pad);$lonPad=max($pad,50/(60*max(.05,cos(deg2rad(max(abs($s),abs($n)))))));$w=min($lons)-$lonPad;$e=max($lons)+$lonPad;if(($e-$w)>180)return[-180,$s,180,$n];return[max(-180,$w),$s,min(180,$e),$n];}
function ycBn2Vertical(array $row,int $fl): array{$min=isset($row['minimum_fl'])&&$row['minimum_fl']!==null?(int)$row['minimum_fl']:null;$max=isset($row['maximum_fl'])&&$row['maximum_fl']!==null?(int)$row['maximum_fl']:null;if($min===null&&$max===null)return['relation'=>'unknown','overlap'=>true];if($min!==null&&$fl<$min)return['relation'=>'below_notam','overlap'=>false];if($max!==null&&$fl>$max)return['relation'=>'above_notam','overlap'=>false];return['relation'=>'at_cruise_level','overlap'=>true];}
function ycBn2Ident(array $row): string{return ycNotamIdent($row)?:(string)($row['nms_id']??'NOTAM');}

function ycBn2DaySet(string $s): ?array{
    $days=['MON'=>1,'TUE'=>2,'WED'=>3,'THU'=>4,'FRI'=>5,'SAT'=>6,'SUN'=>7];
    if(str_contains($s,'DAILY'))return array_values($days);
    $set=[];
    foreach($days as $name=>$num)if(preg_match('/\b'.$name.'\b/',$s))$set[$num]=true;
    foreach($days as $a=>$an)foreach($days as $b=>$bn)if(preg_match('/\b'.$a.'-'.$b.'\b/',$s)){$i=$an;while(true){$set[$i]=true;if($i===$bn)break;$i=$i===7?1:$i+1;if(count($set)>7)break;}}
    return $set?array_keys($set):null;
}
function ycBn2Clock(DateTimeImmutable $date,string $hhmm): DateTimeImmutable{
    $h=(int)substr($hhmm,0,2);$m=(int)substr($hhmm,2,2);$base=$date->setTime(0,0,0);if($h===24&&$m===0)return$base->modify('+1 day');return$base->setTime(min(23,$h),min(59,$m),0);
}
function ycBn2Schedule(string $schedule,DateTimeImmutable $from,DateTimeImmutable $to): array {
    $u=strtoupper(trim(preg_replace('/\s+/',' ',$schedule)??$schedule));
    if($u==='')return ['relation'=>'not_provided','parsed'=>true];
    if($u==='H24')return ['relation'=>'active','parsed'=>true];
    // Only a complete supported expression may exclude a record. Dates, exceptions,
    // solar times and multiple day/time groups remain visible for manual checking.
    $day='(?:MON|TUE|WED|THU|FRI|SAT|SUN)';
    $prefix='(?:DAILY|'.$day.'(?:-'.$day.')?(?:[ ,]+'.$day.'(?:-'.$day.')?)*)';
    $clock='(?:[01][0-9]|2[0-3])[0-5][0-9]';
    $range=$clock.'-(?:'.$clock.'|2400)';
    if(!preg_match('/^(?:('.$prefix.') )?('.$range.'(?:[ ,]+'.$range.')*)$/',$u,$parts))return ['relation'=>'unknown','parsed'=>false];
    preg_match_all('/(\d{4})-(\d{4})/',$parts[2],$m,PREG_SET_ORDER);
    $daySet=ycBn2DaySet($parts[1]??'');$cursor=$from->setTime(0,0)->modify('-1 day');$end=$to->setTime(0,0);$loops=0;
    while($cursor<=$end&&$loops++<18){
        if($daySet===null||in_array((int)$cursor->format('N'),$daySet,true))foreach($m as $r){
            $a=ycBn2Clock($cursor,$r[1]);$b=ycBn2Clock($cursor,$r[2]);if($b<=$a)$b=$b->modify('+1 day');
            if($a<=$to&&$b>=$from)return ['relation'=>'active','parsed'=>true];
        }
        $cursor=$cursor->modify('+1 day');
    }
    return $cursor<=$end?['relation'=>'unknown','parsed'=>false]:['relation'=>'inactive','parsed'=>true];
}

function ycBn2Enrich(array $payload,array $query,?PDO $database=null): array{
    $route=array_values(array_filter($payload['route']??[],static fn($p)=>is_array($p)&&count($p)>=2&&is_numeric($p[0])&&is_numeric($p[1])));if(count($route)<2)return$payload;
    $from=strtoupper(trim((string)($query['from']??($payload['routeInput']['structure']['departure']['airport']??''))));$to=strtoupper(trim((string)($query['to']??($payload['routeInput']['structure']['arrival']['airport']??''))));
    $flight=is_array($payload['flight']??null)?$payload['flight']:[];$fl=max(0,min(600,(int)($flight['cruiseFL']??($query['fl']??0))));$etd=ycBn2Utc($flight['etdUtc']??($query['etd']??null));$eta=ycBn2Utc($flight['estimatedArrivalUtc']??null,$etd->modify('+12 hours'));if($eta<$etd)$eta=$etd->modify('+12 hours');
    [$w,$s,$e,$n]=ycBn2Bbox($route);$bbox=sprintf('POLYGON((%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F))',$w,$s,$e,$s,$e,$n,$w,$n,$w,$s);$refs=ycBn2RouteRefs($payload,$query);
    $regex='';if($refs){$escaped=array_map(static fn($v)=>preg_quote($v,'/'),$refs);$regex='(^|[^A-Z0-9])('.implode('|',$escaped).')([^A-Z0-9]|$)';}
    $pdo=$database??nmsDb();$parts=["(n.geometry IS NOT NULL AND MBRIntersects(n.geometry, ST_GeomFromText(:bbox)))","UPPER(COALESCE(n.icao_location,'')) IN (:dep,:arr)","UPPER(COALESCE(n.location,'')) IN (:dep2,:arr2)"];
    $params=['source'=>YC_NOTAM_SOURCE,'environment'=>YC_NOTAM_ENVIRONMENT,'window_start'=>$etd->modify('-90 minutes')->format('Y-m-d H:i:s'),'window_end'=>$eta->modify('+90 minutes')->format('Y-m-d H:i:s'),'bbox'=>$bbox,'dep'=>$from,'arr'=>$to,'dep2'=>$from,'arr2'=>$to];if($regex!==''){$parts[]="UPPER(COALESCE(n.notam_text,'')) REGEXP :rr";$params['rr']=$regex;}
    $sql="SELECT n.nms_id,n.series,n.number,n.year,n.notam_type,n.classification,n.affected_fir,n.location,n.icao_location,n.selection_code,n.traffic,n.purpose,n.scope,n.minimum_fl,n.maximum_fl,n.effective_start,n.effective_end,n.effective_end_raw,n.schedule,n.lower_limit,n.upper_limit,n.radius_nm,n.status,n.last_updated,n.notam_text,ST_AsGeoJSON(n.geometry,6) geometry_json FROM notams n WHERE n.source=:source AND n.environment=:environment AND n.status<>'cancelled' AND (n.effective_start IS NULL OR n.effective_start<=:window_end) AND (UPPER(COALESCE(n.effective_end_raw,''))='PERM' OR n.effective_end IS NULL OR n.effective_end>=:window_start) AND (".implode(' OR ',$parts).") ORDER BY CASE WHEN UPPER(COALESCE(NULLIF(n.icao_location,''),n.location,'')) IN (:order_dep,:order_arr) THEN 0 ELSE 1 END,n.last_updated DESC LIMIT 2001";
    $params['order_dep']=$from;$params['order_arr']=$to;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidateTruncated=count($rows)>2000;$rows=array_slice($rows,0,2000);
    $lastSync=null;try{$sync=$pdo->prepare('SELECT last_successful_sync FROM notam_sync_state WHERE source=? AND environment=?');$sync->execute([YC_NOTAM_SOURCE,YC_NOTAM_ENVIRONMENT]);$lastSync=$sync->fetchColumn()?:null;}catch(Throwable $e){error_log('[briefing-notam-sync] status unavailable');}
    $syncEpoch=$lastSync?strtotime($lastSync.' UTC'):false;$syncAge=$syncEpoch===false?null:max(0,time()-$syncEpoch);
    $freshness=$syncAge===null?'unknown':($syncAge>900?'stale':'fresh');
    $items=[];$scheduleExcluded=0;$scheduleUnknown=0;$scheduleParsed=0;
    foreach($rows as $row){
        $geometry=json_decode((string)($row['geometry_json']??''),true);$geometry=is_array($geometry)?$geometry:null;$distance=ycBn2GeometryDistance($route,$geometry);$distanceNm=is_finite($distance)?round($distance,1):null;
        $loc=strtoupper(trim((string)($row['icao_location']?:($row['location']??''))));$endpointRole=$loc===$from?'departure':($loc===$to?'arrival':null);$endpoint=$endpointRole!==null;$text=(string)($row['notam_text']??'');$upper=strtoupper($text);$matched=[];foreach($refs as $ref)if(preg_match('/(^|[^A-Z0-9])'.preg_quote($ref,'/').'([^A-Z0-9]|$)/',$upper))$matched[]=$ref;
        $vertical=ycBn2Vertical($row,$fl);$intersects=$distanceNm!==null&&$distanceNm<=0.5;$near=$distanceNm!==null&&$distanceNm<=50.0;$refMatch=!empty($matched);$relevant=$endpoint||$refMatch||($near&&$vertical['overlap']);if(!$relevant)continue;
        $basis=$endpoint?'endpoint':($refMatch?'route_reference':($intersects?'route_intersection':'near_route'));
        $schedFrom=$endpointRole==='departure'?$etd->modify('-90 minutes'):($endpointRole==='arrival'?$eta->modify('-90 minutes'):$etd);$schedTo=$endpointRole==='departure'?$etd->modify('+90 minutes'):($endpointRole==='arrival'?$eta->modify('+90 minutes'):$eta);
        $validFrom=!empty($row['effective_start'])?ycBn2Utc($row['effective_start']):null;
        $validTo=!empty($row['effective_end'])?ycBn2Utc($row['effective_end']):null;
        if(($validFrom&&$validFrom>$schedTo)||($validTo&&strtoupper((string)$row['effective_end_raw'])!=='PERM'&&$validTo<$schedFrom))continue;
        if($validFrom&&$validFrom>$schedFrom)$schedFrom=$validFrom;
        if($validTo&&strtoupper((string)$row['effective_end_raw'])!=='PERM'&&$validTo<$schedTo)$schedTo=$validTo;
        $schedule=ycBn2Schedule((string)($row['schedule']??''),$schedFrom,$schedTo);if($schedule['parsed'])$scheduleParsed++;if($schedule['relation']==='inactive'){$scheduleExcluded++;continue;}if($schedule['relation']==='unknown')$scheduleUnknown++;
        $semantic=ycBn2Semantic($row['selection_code']??null);$priority=ycBn2Priority($semantic,$text);$priorityPenalty=$priority==='high'?-18.0:($priority==='medium'?0.0:18.0);$score=($endpoint?0.0:($refMatch?8.0:20.0+(float)($distanceNm??100)))+$priorityPenalty;if(!$vertical['overlap']&&!$endpoint)$score+=35.0;if($schedule['relation']==='unknown')$score+=6.0;
        $items[]=['_score'=>$score,'id'=>(string)($row['nms_id']??''),'ident'=>ycBn2Ident($row),'location'=>$row['icao_location']?:($row['location']??null),'fir'=>$row['affected_fir']??null,'semantic'=>$semantic,'priority'=>$priority,'basis'=>$basis,'endpointRole'=>$endpointRole,'distanceNm'=>$distanceNm,'matchedRouteRefs'=>$matched,'verticalRelation'=>$vertical['relation'],'cruiseLevelOverlap'=>(bool)$vertical['overlap'],'minimumFl'=>isset($row['minimum_fl'])&&$row['minimum_fl']!==null?(int)$row['minimum_fl']:null,'maximumFl'=>isset($row['maximum_fl'])&&$row['maximum_fl']!==null?(int)$row['maximum_fl']:null,'effectiveStart'=>$row['effective_start']??null,'effectiveEnd'=>$row['effective_end']??null,'effectiveEndRaw'=>$row['effective_end_raw']??null,'schedule'=>$row['schedule']??null,'scheduleRelation'=>$schedule['relation'],'text'=>$text];
    }
    usort($items,static fn($a,$b)=>($a['_score']<=>$b['_score'])?:strcmp((string)$a['ident'],(string)$b['ident']));$totalMatched=count($items);$allItems=$items;$items=array_slice($items,0,80);foreach($items as &$i)unset($i['_score']);unset($i);
    $countBy=static fn(string $k,string $v)=>count(array_filter($allItems,static fn($i)=>($i[$k]??null)===$v));
    $high=$countBy('priority','high');$medium=$countBy('priority','medium');$info=$countBy('priority','info');$endpoint=$countBy('basis','endpoint');$hit=$countBy('basis','route_intersection');$near=$countBy('basis','near_route');$ref=$countBy('basis','route_reference');$cruise=$countBy('verticalRelation','at_cruise_level');$unknownLevel=$countBy('verticalRelation','unknown');
    $impact=['available'=>true,'source'=>'FAA NMS local MariaDB','checkedAt'=>gmdate('c'),'flightWindow'=>['from'=>$etd->format(DATE_ATOM),'to'=>$eta->format(DATE_ATOM)],'cruiseFL'=>$fl,'routeCorridorNm'=>50,'routeReferences'=>$refs,'candidateCount'=>count($rows),'matchedCount'=>$totalMatched,'relevantCount'=>$totalMatched,'shownCount'=>count($items),'itemsTruncated'=>$totalMatched>count($items),'candidatesTruncated'=>$candidateTruncated,'sourceFreshness'=>$freshness,'lastSuccessfulSyncUtc'=>$syncEpoch===false?null:gmdate('c',$syncEpoch),'syncAgeSeconds'=>$syncAge,'coverageComplete'=>!$candidateTruncated&&$freshness==='fresh','unknownLevelCount'=>$unknownLevel,'highPriorityCount'=>$high,'mediumPriorityCount'=>$medium,'infoCount'=>$info,'operationalCount'=>$high+$medium,'endpointCount'=>$endpoint,'routeIntersectionCount'=>$hit,'nearRouteCount'=>$near,'referenceMatchCount'=>$ref,'atCruiseLevelCount'=>$cruise,'scheduleEvaluated'=>$scheduleUnknown===0,'scheduleParsedCount'=>$scheduleParsed,'scheduleExcludedCount'=>$scheduleExcluded,'scheduleUnknownCount'=>$scheduleUnknown,'items'=>$items];$payload['notamImpact']=$impact;
    if(!isset($payload['sourceStatus'])||!is_array($payload['sourceStatus']))$payload['sourceStatus']=[];$payload['sourceStatus']['notam']=['ok'=>true,'source'=>'FAA NMS local MariaDB','candidateCount'=>count($rows),'matchedCount'=>$totalMatched,'sourceFreshness'=>$freshness,'highPriorityCount'=>$high,'scheduleExcludedCount'=>$scheduleExcluded,'scheduleUnknownCount'=>$scheduleUnknown];
    if(!isset($payload['routeEngine'])||!is_array($payload['routeEngine']))$payload['routeEngine']=[];$warnings=is_array($payload['routeEngine']['warnings']??null)?$payload['routeEngine']['warnings']:[];
    if($items){$warnings[]=sprintf('NOTAM check: %d active/potential match(es), %d high priority; %d schedule-inactive record(s) excluded.',$totalMatched,$high,$scheduleExcluded);foreach(array_slice($items,0,3)as$i){$where=$i['location']?:($i['fir']?:'route');$warnings[]=sprintf('NOTAM %s · %s · %s · %s · %s',$i['ident'],$where,strtoupper($i['priority']),$i['semantic'],strtoupper($i['basis']));}}else{$warnings[]=$candidateTruncated||$freshness!=='fresh'?'NOTAM check incomplete: no match in available subset; source coverage is uncertain.':'NOTAM check: no active route/airport match detected for the evaluated window.';}
    $payload['routeEngine']['warnings']=array_values(array_unique(array_filter(array_map('strval',$warnings))));return$payload;
}
function ycBn2Failure(array $payload): array{$payload['notamImpact']=['available'=>false,'source'=>'FAA NMS local MariaDB','checkedAt'=>gmdate('c'),'error'=>'NOTAM relevance check unavailable.'];if(!isset($payload['sourceStatus'])||!is_array($payload['sourceStatus']))$payload['sourceStatus']=[];$payload['sourceStatus']['notam']=['ok'=>false,'error'=>'NOTAM relevance check unavailable.'];return$payload;}

if(defined('YC_BRIEFING_TEST_MODE'))return;

ob_start();
register_shutdown_function(static function():void{
    $body='';
    if(ob_get_level()>0){$body=(string)ob_get_contents();@ob_end_clean();}
    if($body==='')return;
    $status=http_response_code();
    $payload=json_decode($body,true);
    if($status<200||$status>=300||!is_array($payload)||!($payload['ok']??false)){echo$body;return;}
    try{$payload=ycBn2Enrich($payload,$_GET);}
    catch(Throwable $e){error_log('[briefing-notam] '.$e->getMessage());$payload=ycBn2Failure($payload);}
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
