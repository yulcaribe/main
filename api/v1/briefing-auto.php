<?php
declare(strict_types=1);

/**
 * Estimated navdata auto-router for Pilot Briefing.
 *
 * When the caller does not supply an OFP/route, this layer uses the great-circle
 * track only as a search guide, finds a connected airway path in the local
 * MariaDB navdata graph, injects that estimated route into the existing briefing
 * engine, and lets the normal weather/SIGMET/NOTAM pipeline run on that route.
 *
 * This is advisory/estimated routing. It is not IFPS/Eurocontrol validation.
 */

require_once dirname(__DIR__, 2) . '/notam/core.php';

function ycAutoRouteRad(float $d): float { return $d * M_PI / 180.0; }
function ycAutoRouteDeg(float $r): float { return $r * 180.0 / M_PI; }

function ycAutoRouteNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 3440.065;
    $p1 = ycAutoRouteRad($lat1); $p2 = ycAutoRouteRad($lat2);
    $dp = ycAutoRouteRad($lat2 - $lat1); $dl = ycAutoRouteRad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))));
}

function ycAutoRouteGreatCircle(float $lat1, float $lon1, float $lat2, float $lon2, int $count = 48): array {
    $p1 = ycAutoRouteRad($lat1); $l1 = ycAutoRouteRad($lon1);
    $p2 = ycAutoRouteRad($lat2); $l2 = ycAutoRouteRad($lon2);
    $delta = 2 * asin(sqrt(sin(($p2-$p1)/2)**2 + cos($p1)*cos($p2)*sin(($l2-$l1)/2)**2));
    if ($delta < 1e-9) return [[$lat1,$lon1],[$lat2,$lon2]];
    $out=[];
    for($i=0;$i<$count;$i++){
        $f=$i/max(1,$count-1);
        $a=sin((1-$f)*$delta)/sin($delta); $b=sin($f*$delta)/sin($delta);
        $x=$a*cos($p1)*cos($l1)+$b*cos($p2)*cos($l2);
        $y=$a*cos($p1)*sin($l1)+$b*cos($p2)*sin($l2);
        $z=$a*sin($p1)+$b*sin($p2);
        $out[]=[ycAutoRouteDeg(atan2($z,sqrt($x*$x+$y*$y))),ycAutoRouteDeg(atan2($y,$x))];
    }
    return $out;
}

function ycAutoRoutePointSegmentNm(float $lat,float $lon,float $lat1,float $lon1,float $lat2,float $lon2): float {
    $lat0=ycAutoRouteRad(($lat+$lat1+$lat2)/3.0);
    $x=($lon-$lon1)*cos($lat0)*60.0; $y=($lat-$lat1)*60.0;
    $dx=($lon2-$lon1)*cos($lat0)*60.0; $dy=($lat2-$lat1)*60.0;
    $den=$dx*$dx+$dy*$dy;
    if($den<1e-12) return sqrt($x*$x+$y*$y);
    $t=max(0.0,min(1.0,($x*$dx+$y*$dy)/$den));
    return sqrt(($x-$t*$dx)**2+($y-$t*$dy)**2);
}

function ycAutoRouteDistanceToGuide(array $guide,float $lat,float $lon): float {
    $best=INF;
    for($i=0;$i<count($guide)-1;$i++){
        $a=$guide[$i]; $b=$guide[$i+1];
        $best=min($best,ycAutoRoutePointSegmentNm($lat,$lon,(float)$a[0],(float)$a[1],(float)$b[0],(float)$b[1]));
    }
    return $best;
}

function ycAutoRouteGeometryLines(?string $json): array {
    if(!$json) return [];
    $g=json_decode($json,true); if(!is_array($g)) return [];
    $type=$g['type']??''; $coords=$g['coordinates']??null;
    if($type==='LineString'&&is_array($coords)) return [$coords];
    if($type==='MultiLineString'&&is_array($coords)) return $coords;
    return [];
}

function ycAutoRouteBestLine(array $lines): array {
    $best=[]; $bestLen=-1.0;
    foreach($lines as $line){
        if(!is_array($line)||count($line)<2) continue;
        $len=0.0;
        for($i=0;$i<count($line)-1;$i++){
            $a=$line[$i]; $b=$line[$i+1];
            if(!is_array($a)||!is_array($b)||count($a)<2||count($b)<2) continue;
            $len+=ycAutoRouteNm((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
        }
        if($len>$bestLen){$bestLen=$len;$best=$line;}
    }
    return $best;
}

function ycAutoRouteLevel(?string $text): ?int {
    $t=strtoupper(trim((string)$text)); if($t==='') return null;
    if(preg_match('/\bFL\s*([0-9]{2,3})\b/',$t,$m)) return (int)$m[1];
    if(preg_match('/\bF\s*([0-9]{2,3})\b/',$t,$m)) return (int)$m[1];
    if(preg_match('/\b([0-9]{3,5})\s*FT\b/',$t,$m)) return (int)round(((int)$m[1])/100);
    return null;
}

function ycAutoRouteLevelAllowed(array $row,int $fl): bool {
    $lo=ycAutoRouteLevel($row['lower_text']??null);
    $hi=!empty($row['upper_unlimited'])?null:ycAutoRouteLevel($row['upper_text']??null);
    if($lo!==null&&$fl<$lo) return false;
    if($hi!==null&&$fl>$hi) return false;
    return true;
}

function ycAutoRouteAirport(PDO $pdo,string $ident): ?array {
    $stmt=$pdo->prepare("SELECT ident,lat,lon FROM nav_points WHERE kind='airport' AND (UPPER(ident)=:id OR UPPER(COALESCE(iata,''))=:id2) ORDER BY (UPPER(ident)=:ord) DESC LIMIT 1");
    $stmt->execute(['id'=>$ident,'id2'=>$ident,'ord'=>$ident]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$r||!is_numeric($r['lat']??null)||!is_numeric($r['lon']??null)) return null;
    return ['ident'=>strtoupper((string)$r['ident']),'lat'=>(float)$r['lat'],'lon'=>(float)$r['lon']];
}

function ycAutoRoutePointCoordinates(PDO $pdo,array $idents): array {
    $idents=array_values(array_unique(array_filter(array_map('strtoupper',$idents))));
    $out=[];
    foreach(array_chunk($idents,400) as $chunk){
        $ph=implode(',',array_fill(0,count($chunk),'?'));
        $stmt=$pdo->prepare("SELECT ident,lat,lon,kind FROM nav_points WHERE ident IN ($ph) AND lat IS NOT NULL AND lon IS NOT NULL ORDER BY CASE kind WHEN 'designatedpoint' THEN 0 WHEN 'navaid' THEN 1 ELSE 2 END");
        $stmt->execute($chunk);
        while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
            $id=strtoupper((string)$r['ident']);
            if(!isset($out[$id])) $out[$id]=['lat'=>(float)$r['lat'],'lon'=>(float)$r['lon']];
        }
    }
    return $out;
}

function ycAutoRouteBuild(PDO $pdo,string $from,string $to,int $fl): ?array {
    $dep=ycAutoRouteAirport($pdo,$from); $arr=ycAutoRouteAirport($pdo,$to);
    if(!$dep||!$arr) return null;
    $direct=ycAutoRouteNm($dep['lat'],$dep['lon'],$arr['lat'],$arr['lon']);
    if($direct<90.0||$direct>5200.0) return null;
    if(abs($dep['lon']-$arr['lon'])>170.0) return null;

    $guide=ycAutoRouteGreatCircle($dep['lat'],$dep['lon'],$arr['lat'],$arr['lon'],max(36,min(90,(int)ceil($direct/55)+1)));
    $pad=max(2.2,min(6.0,$direct/500.0));
    $south=max(-84.0,min($dep['lat'],$arr['lat'])-$pad); $north=min(84.0,max($dep['lat'],$arr['lat'])+$pad);
    $west=max(-179.5,min($dep['lon'],$arr['lon'])-$pad); $east=min(179.5,max($dep['lon'],$arr['lon'])+$pad);
    $bbox=sprintf('POLYGON((%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F))',$west,$south,$east,$south,$east,$north,$west,$north,$west,$south);

    $stmt=$pdo->prepare("SELECT rg.id AS geometry_id,rg.from_ident,rg.to_ident,ST_AsGeoJSON(rg.geom,6) AS geometry,r.id AS route_id,r.ident,rm.forward,rm.backward,rm.lower_text,rm.upper_text,rm.upper_unlimited FROM nav_route_geometry rg JOIN nav_route_memberships rm ON rm.segment_id=rg.segment_id JOIN nav_routes r ON r.id=rm.route_id WHERE r.type='airway' AND MBRIntersects(rg.geom,ST_GeomFromText(:bbox)) LIMIT 42000");
    $stmt->execute(['bbox'=>$bbox]);

    $corridor=max(180.0,min(360.0,$direct*0.16));
    $segments=[]; $idents=[];
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
        $fromId=strtoupper(trim((string)($r['from_ident']??''))); $toId=strtoupper(trim((string)($r['to_ident']??'')));
        $airway=strtoupper(trim((string)($r['ident']??'')));
        if($fromId===''||$toId===''||$airway===''||!ycAutoRouteLevelAllowed($r,$fl)) continue;
        $line=ycAutoRouteBestLine(ycAutoRouteGeometryLines($r['geometry']??null));
        if(count($line)<2) continue;
        $mid=$line[(int)floor((count($line)-1)/2)];
        $off=ycAutoRouteDistanceToGuide($guide,(float)$mid[1],(float)$mid[0]);
        if($off>$corridor) continue;
        $len=0.0;
        for($i=0;$i<count($line)-1;$i++) $len+=ycAutoRouteNm((float)$line[$i][1],(float)$line[$i][0],(float)$line[$i+1][1],(float)$line[$i+1][0]);
        if($len<=0.0||$len>700.0) continue;
        $segments[]=['from'=>$fromId,'to'=>$toId,'airway'=>$airway,'len'=>$len,'off'=>$off,'forward'=>$r['forward'],'backward'=>$r['backward'],'first'=>$line[0],'last'=>$line[count($line)-1]];
        $idents[]=$fromId; $idents[]=$toId;
    }
    if(count($segments)<2) return null;

    $coords=ycAutoRoutePointCoordinates($pdo,$idents);
    foreach($segments as $s){
        if(!isset($coords[$s['from']])) $coords[$s['from']]=['lat'=>(float)$s['first'][1],'lon'=>(float)$s['first'][0]];
        if(!isset($coords[$s['to']])) $coords[$s['to']]=['lat'=>(float)$s['last'][1],'lon'=>(float)$s['last'][0]];
    }

    $graph=[];
    foreach($segments as $s){
        $f=$s['forward']; $b=$s['backward'];
        $allowF=$f===null&&$b===null?true:(int)$f===1;
        $allowB=$f===null&&$b===null?true:(int)$b===1;
        $penalty=3.0+$s['len']*(min(1.5,$s['off']/max(1.0,$corridor))*0.18);
        $w=$s['len']+$penalty;
        if($allowF) $graph[$s['from']][]=['from'=>$s['from'],'to'=>$s['to'],'airway'=>$s['airway'],'weight'=>$w];
        if($allowB) $graph[$s['to']][]=['from'=>$s['to'],'to'=>$s['from'],'airway'=>$s['airway'],'weight'=>$w];
    }
    if(!$graph) return null;

    $entry=[]; $exit=[]; $terminalRadius=max(140.0,min(240.0,$direct*0.18));
    foreach($coords as $id=>$c){
        if(!isset($graph[$id])) continue;
        $d1=ycAutoRouteNm($dep['lat'],$dep['lon'],$c['lat'],$c['lon']);
        $d2=ycAutoRouteNm($arr['lat'],$arr['lon'],$c['lat'],$c['lon']);
        $off=ycAutoRouteDistanceToGuide($guide,$c['lat'],$c['lon']);
        if($d1<=$terminalRadius) $entry[]=['id'=>$id,'d'=>$d1,'score'=>$d1+$off*.35];
        if($d2<=$terminalRadius) $exit[]=['id'=>$id,'d'=>$d2,'score'=>$d2+$off*.35];
    }
    usort($entry,fn($a,$b)=>$a['score']<=>$b['score']); usort($exit,fn($a,$b)=>$a['score']<=>$b['score']);
    $entry=array_slice($entry,0,18); $exit=array_slice($exit,0,18);
    if(!$entry||!$exit) return null;
    $goalPenalty=[]; foreach($exit as $g)$goalPenalty[$g['id']]=$g['d']*1.12;

    $dist=[]; $prev=[]; $pq=new SplPriorityQueue(); $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    foreach($entry as $e){$cost=$e['d']*1.12;if(!isset($dist[$e['id']])||$cost<$dist[$e['id']]){$dist[$e['id']]=$cost;$pq->insert($e['id'],-$cost);}}
    $bestGoal=null; $bestTotal=INF; $visited=0;
    while(!$pq->isEmpty()&&$visited<90000){
        $cur=$pq->extract(); $node=(string)$cur['data']; $cost=-(float)$cur['priority'];
        if($cost>($dist[$node]??INF)+0.0001) continue; $visited++;
        if($cost>$bestTotal) break;
        if(isset($goalPenalty[$node])){$total=$cost+$goalPenalty[$node];if($total<$bestTotal){$bestTotal=$total;$bestGoal=$node;}}
        foreach($graph[$node]??[] as $edge){
            $next=$edge['to']; $nc=$cost+(float)$edge['weight'];
            if($nc+0.0001<($dist[$next]??INF)){$dist[$next]=$nc;$prev[$next]=[$node,$edge];$pq->insert($next,-$nc);}
        }
    }
    if($bestGoal===null) return null;

    $edges=[]; $node=$bestGoal;
    while(isset($prev[$node])){[$pn,$edge]=$prev[$node];array_unshift($edges,$edge);$node=$pn;if(count($edges)>600)return null;}
    $start=$node;
    if(!$edges) return null;

    $tokens=[$start]; $group=$edges[0]['airway'];
    foreach($edges as $i=>$edge){
        if($edge['airway']!==$group){$tokens[]=$group;$tokens[]=$edge['from'];$group=$edge['airway'];}
    }
    $tokens[]=$group; $tokens[]=$edges[count($edges)-1]['to'];
    $route=implode(' ',array_values(array_filter($tokens)));
    if(strlen($route)>1800) return null;

    return ['route'=>$route,'directNm'=>round($direct,1),'graphCostNm'=>round($bestTotal,1),'segments'=>count($edges),'airways'=>count(array_unique(array_column($edges,'airway'))),'entry'=>$start,'exit'=>$bestGoal];
}

$routeSupplied=trim((string)($_GET['route']??''))!=='';
if(!$routeSupplied){
    $from=strtoupper(trim((string)($_GET['from']??''))); $to=strtoupper(trim((string)($_GET['to']??'')));
    $fl=max(50,min(600,(int)($_GET['fl']??360)));
    if(preg_match('/^[A-Z0-9]{4}$/',$from)&&preg_match('/^[A-Z0-9]{4}$/',$to)&&$from!==$to){
        try{
            $auto=ycAutoRouteBuild(nmsDb(),$from,$to,$fl);
            if($auto&&trim((string)$auto['route'])!==''){
                $_GET['route']=$auto['route'];
                $_GET['_yc_auto_navdata']='1';
                header('X-YC-Auto-Route: navdata');
                header('X-YC-Auto-Route-Segments: '.(int)$auto['segments']);
                header('X-YC-Auto-Route-Airways: '.(int)$auto['airways']);
            }else{
                header('X-YC-Auto-Route: great-circle');
            }
        }catch(Throwable $e){
            error_log('[briefing-auto] '.$e->getMessage());
            header('X-YC-Auto-Route: great-circle');
        }
    }
}

require __DIR__ . '/briefing-notam.php';
