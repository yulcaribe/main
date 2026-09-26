<?php
declare(strict_types=1);

/**
 * Pilot Briefing estimated navdata auto-router v2.
 * Great-circle is only a search guide. Prefer published airway topology and
 * permit short DCT bridges between disconnected airway components (FRA/gaps).
 * Advisory only; never IFPS/Eurocontrol validation.
 */

require_once dirname(__DIR__, 2) . '/notam/core.php';

function ycAr2Rad(float $d): float { return $d * M_PI / 180.0; }
function ycAr2Deg(float $r): float { return $r * 180.0 / M_PI; }
function ycAr2Fail(string $reason): ?array { $GLOBALS['ycAr2LastReason']=$reason; return null; }

function ycAr2Nm(float $lat1,float $lon1,float $lat2,float $lon2): float {
    $r=3440.065;
    $p1=ycAr2Rad($lat1); $p2=ycAr2Rad($lat2);
    $dp=ycAr2Rad($lat2-$lat1); $dl=ycAr2Rad($lon2-$lon1);
    $a=sin($dp/2)**2+cos($p1)*cos($p2)*sin($dl/2)**2;
    return $r*(2*atan2(sqrt($a),sqrt(max(0.0,1.0-$a))));
}

function ycAr2GreatCircle(float $lat1,float $lon1,float $lat2,float $lon2,int $count): array {
    $p1=ycAr2Rad($lat1); $l1=ycAr2Rad($lon1); $p2=ycAr2Rad($lat2); $l2=ycAr2Rad($lon2);
    $delta=2*asin(sqrt(sin(($p2-$p1)/2)**2+cos($p1)*cos($p2)*sin(($l2-$l1)/2)**2));
    if($delta<1e-9) return [[$lat1,$lon1],[$lat2,$lon2]];
    $out=[];
    for($i=0;$i<$count;$i++){
        $f=$i/max(1,$count-1);
        $a=sin((1-$f)*$delta)/sin($delta); $b=sin($f*$delta)/sin($delta);
        $x=$a*cos($p1)*cos($l1)+$b*cos($p2)*cos($l2);
        $y=$a*cos($p1)*sin($l1)+$b*cos($p2)*sin($l2);
        $z=$a*sin($p1)+$b*sin($p2);
        $out[]=[ycAr2Deg(atan2($z,sqrt($x*$x+$y*$y))),ycAr2Deg(atan2($y,$x))];
    }
    return $out;
}

function ycAr2SegmentMetric(float $lat,float $lon,float $lat1,float $lon1,float $lat2,float $lon2): array {
    $lat0=ycAr2Rad(($lat+$lat1+$lat2)/3.0);
    $x=($lon-$lon1)*cos($lat0)*60.0; $y=($lat-$lat1)*60.0;
    $dx=($lon2-$lon1)*cos($lat0)*60.0; $dy=($lat2-$lat1)*60.0;
    $den=$dx*$dx+$dy*$dy;
    $t=$den<1e-12?0.0:max(0.0,min(1.0,($x*$dx+$y*$dy)/$den));
    return [sqrt(($x-$t*$dx)**2+($y-$t*$dy)**2),$t];
}

function ycAr2GuideMetric(array $guide,float $lat,float $lon): array {
    $best=INF; $progress=0.0; $n=max(1,count($guide)-1);
    for($i=0;$i<count($guide)-1;$i++){
        $a=$guide[$i]; $b=$guide[$i+1];
        [$d,$t]=ycAr2SegmentMetric($lat,$lon,(float)$a[0],(float)$a[1],(float)$b[0],(float)$b[1]);
        if($d<$best){$best=$d;$progress=($i+$t)/$n;}
    }
    return ['off'=>$best,'progress'=>max(0.0,min(1.0,$progress))];
}

function ycAr2GeoLines(?string $json): array {
    if(!$json) return [];
    $g=json_decode($json,true); if(!is_array($g)) return [];
    $t=$g['type']??''; $c=$g['coordinates']??null;
    if($t==='LineString'&&is_array($c)) return [$c];
    if($t==='MultiLineString'&&is_array($c)) return $c;
    return [];
}

function ycAr2BestLine(array $lines): array {
    $best=[];$bestLen=-1.0;
    foreach($lines as $line){
        if(!is_array($line)||count($line)<2) continue;
        $len=0.0;
        for($i=0;$i<count($line)-1;$i++){
            $a=$line[$i];$b=$line[$i+1];
            if(!is_array($a)||!is_array($b)||count($a)<2||count($b)<2) continue;
            $len+=ycAr2Nm((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
        }
        if($len>$bestLen){$bestLen=$len;$best=$line;}
    }
    return $best;
}

function ycAr2Level(?string $text): ?int {
    $t=strtoupper(trim((string)$text)); if($t==='') return null;
    if(preg_match('/\bFL\s*([0-9]{2,3})\b/',$t,$m)) return (int)$m[1];
    if(preg_match('/\bF\s*([0-9]{2,3})\b/',$t,$m)) return (int)$m[1];
    if(preg_match('/\b([0-9]{3,5})\s*FT\b/',$t,$m)) return (int)round(((int)$m[1])/100);
    if(preg_match('/^([0-9]{2,3})$/',$t,$m)) return (int)$m[1];
    return null;
}

function ycAr2LevelAllowed(array $row,int $fl): bool {
    $lo=ycAr2Level($row['lower_text']??null);
    $hi=!empty($row['upper_unlimited'])?null:ycAr2Level($row['upper_text']??null);
    if($lo!==null&&$fl<$lo) return false;
    if($hi!==null&&$fl>$hi) return false;
    return true;
}

function ycAr2Airport(PDO $pdo,string $ident): ?array {
    $stmt=$pdo->prepare("SELECT ident,lat,lon FROM nav_points WHERE kind='airport' AND (UPPER(ident)=:id OR UPPER(COALESCE(iata,''))=:id2) ORDER BY (UPPER(ident)=:ord) DESC LIMIT 1");
    $stmt->execute(['id'=>$ident,'id2'=>$ident,'ord'=>$ident]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$r||!is_numeric($r['lat']??null)||!is_numeric($r['lon']??null)) return null;
    return ['ident'=>strtoupper((string)$r['ident']),'lat'=>(float)$r['lat'],'lon'=>(float)$r['lon']];
}

function ycAr2RememberCoord(array &$coords,array &$metrics,string $id,array $coord,array $guide): void {
    if($id===''||count($coord)<2) return;
    $candidate=['lat'=>(float)$coord[1],'lon'=>(float)$coord[0]];
    $metric=ycAr2GuideMetric($guide,$candidate['lat'],$candidate['lon']);
    if(!isset($coords[$id])||$metric['off']<($metrics[$id]['off']??INF)){
        $coords[$id]=$candidate;
        $metrics[$id]=$metric;
    }
}

function ycAr2AddDctBridges(array &$graph,array $coords,array $metrics,float $direct): int {
    $binCount=max(24,min(80,(int)ceil($direct/28.0)));
    $bridgeMax=max(110.0,min(165.0,$direct*0.11));
    $bins=[];
    foreach($coords as $id=>$c){
        if(!array_key_exists($id,$graph)||!isset($metrics[$id])) continue;
        $m=$metrics[$id];
        if($m['off']>190.0) continue;
        $bin=min($binCount-1,max(0,(int)floor($m['progress']*$binCount)));
        $bins[$bin][]=['id'=>$id,'off'=>$m['off'],'p'=>$m['progress']];
    }
    foreach($bins as &$rows){
        usort($rows,static fn($a,$b)=>($a['off']<=>$b['off']) ?: ($a['p']<=>$b['p']));
        $rows=array_slice($rows,0,28);
    }
    unset($rows);

    $added=0; $maxBinJump=max(2,(int)ceil($bridgeMax/max(16.0,$direct/$binCount))+1);
    for($i=0;$i<$binCount;$i++){
        if(empty($bins[$i])) continue;
        for($j=$i+1;$j<=min($binCount-1,$i+$maxBinJump);$j++){
            if(empty($bins[$j])) continue;
            foreach($bins[$i] as $a){
                foreach($bins[$j] as $b){
                    if($b['p']<=$a['p']+0.003) continue;
                    $ca=$coords[$a['id']]??null; $cb=$coords[$b['id']]??null;
                    if(!$ca||!$cb) continue;
                    $d=ycAr2Nm($ca['lat'],$ca['lon'],$cb['lat'],$cb['lon']);
                    if($d<12.0||$d>$bridgeMax) continue;
                    if(($b['p']-$a['p'])<0.008) continue;
                    $weight=$d*1.62+62.0+($a['off']+$b['off'])*0.10;
                    $graph[$a['id']][]=['kind'=>'dct','from'=>$a['id'],'to'=>$b['id'],'airway'=>'DCT','weight'=>$weight,'distanceNm'=>$d,'directionFallback'=>false];
                    $added++;
                    if($added>=9000) return $added;
                }
            }
        }
    }
    return $added;
}

function ycAr2EdgesToRoute(array $edges,string $start): array {
    if(!$edges) return ['route'=>'','airways'=>0,'bridges'=>0,'bridgeNm'=>0.0,'directionFallbacks'=>0];
    $tokens=[$start]; $currentAirway=null; $bridges=0; $bridgeNm=0.0; $airways=[]; $directionFallbacks=0;
    foreach($edges as $edge){
        if(!empty($edge['directionFallback'])) $directionFallbacks++;
        $kind=$edge['kind']??'airway';
        if($kind==='dct'){
            if($currentAirway!==null){$tokens[]=$currentAirway;$tokens[]=$edge['from'];$currentAirway=null;}
            if(end($tokens)!==$edge['from']) $tokens[]=$edge['from'];
            $tokens[]='DCT'; $tokens[]=$edge['to'];
            $bridges++; $bridgeNm+=(float)($edge['distanceNm']??0.0);
            continue;
        }
        $aw=(string)$edge['airway']; $airways[$aw]=true;
        if($currentAirway===null)$currentAirway=$aw;
        elseif($currentAirway!==$aw){$tokens[]=$currentAirway;$tokens[]=$edge['from'];$currentAirway=$aw;}
    }
    $last=$edges[count($edges)-1];
    if($currentAirway!==null){$tokens[]=$currentAirway;$tokens[]=$last['to'];}
    $clean=[];
    foreach($tokens as $t){$t=trim((string)$t);if($t===''||($clean&&end($clean)===$t))continue;$clean[]=$t;}
    return ['route'=>implode(' ',$clean),'airways'=>count($airways),'bridges'=>$bridges,'bridgeNm'=>round($bridgeNm,1),'directionFallbacks'=>$directionFallbacks];
}

function ycAr2Build(PDO $pdo,string $from,string $to,int $fl): ?array {
    $GLOBALS['ycAr2LastReason']='unknown';
    $dep=ycAr2Airport($pdo,$from); $arr=ycAr2Airport($pdo,$to);
    if(!$dep||!$arr) return ycAr2Fail('airport-not-found');
    $direct=ycAr2Nm($dep['lat'],$dep['lon'],$arr['lat'],$arr['lon']);
    if($direct<90.0||$direct>5200.0||abs($dep['lon']-$arr['lon'])>170.0) return ycAr2Fail('distance-out-of-range');

    $guide=ycAr2GreatCircle($dep['lat'],$dep['lon'],$arr['lat'],$arr['lon'],max(40,min(120,(int)ceil($direct/38)+1)));
    $pad=max(3.2,min(8.0,$direct/390.0));
    $south=max(-84.0,min($dep['lat'],$arr['lat'])-$pad); $north=min(84.0,max($dep['lat'],$arr['lat'])+$pad);
    $west=max(-179.5,min($dep['lon'],$arr['lon'])-$pad); $east=min(179.5,max($dep['lon'],$arr['lon'])+$pad);
    $bbox=sprintf('POLYGON((%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F))',$west,$south,$east,$south,$east,$north,$west,$north,$west,$south);

    $stmt=$pdo->prepare("SELECT rg.from_ident,rg.to_ident,ST_AsGeoJSON(rg.geom,6) AS geometry,r.ident,rm.forward,rm.backward,COALESCE(rm.lower_text,s.lower_text) AS lower_text,COALESCE(rm.upper_text,s.upper_text) AS upper_text,CASE WHEN rm.upper_unlimited=1 OR s.upper_unlimited=1 THEN 1 ELSE 0 END AS upper_unlimited FROM nav_route_geometry rg JOIN nav_route_memberships rm ON rm.segment_id=rg.segment_id JOIN nav_route_segments s ON s.id=rm.segment_id JOIN nav_routes r ON r.id=rm.route_id WHERE r.type='airway' AND MBRIntersects(rg.geom,ST_GeomFromText(:bbox)) LIMIT 60000");
    $stmt->execute(['bbox'=>$bbox]);

    $corridor=max(230.0,min(460.0,$direct*0.22));
    $segments=[];$coords=[];$metrics=[];$loaded=0;$levelRejected=0;
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
        $loaded++;
        $a=strtoupper(trim((string)($r['from_ident']??'')));$b=strtoupper(trim((string)($r['to_ident']??'')));$aw=strtoupper(trim((string)($r['ident']??'')));
        if($a===''||$b===''||$aw==='') continue;
        if(!ycAr2LevelAllowed($r,$fl)){$levelRejected++;continue;}
        $line=ycAr2BestLine(ycAr2GeoLines($r['geometry']??null)); if(count($line)<2) continue;
        $mid=$line[(int)floor((count($line)-1)/2)]; $gm=ycAr2GuideMetric($guide,(float)$mid[1],(float)$mid[0]);
        if($gm['off']>$corridor) continue;
        $len=0.0;for($i=0;$i<count($line)-1;$i++)$len+=ycAr2Nm((float)$line[$i][1],(float)$line[$i][0],(float)$line[$i+1][1],(float)$line[$i+1][0]);
        if($len<=0.0||$len>700.0) continue;
        $first=$line[0];$last=$line[count($line)-1];
        $segments[]=['from'=>$a,'to'=>$b,'airway'=>$aw,'len'=>$len,'off'=>$gm['off'],'forward'=>$r['forward'],'backward'=>$r['backward'],'first'=>$first,'last'=>$last];
        ycAr2RememberCoord($coords,$metrics,$a,$first,$guide);
        ycAr2RememberCoord($coords,$metrics,$b,$last,$guide);
    }
    $GLOBALS['ycAr2Stats']=['loaded'=>$loaded,'eligible'=>count($segments),'levelRejected'=>$levelRejected];
    if(count($segments)<2) return ycAr2Fail('no-eligible-airway-segments');

    $graph=[];
    foreach($coords as $id=>$unused)$graph[$id]=[];
    foreach($segments as $s){
        $allowF=$s['forward']===null&&$s['backward']===null?true:(int)$s['forward']===1;
        $allowB=$s['forward']===null&&$s['backward']===null?true:(int)$s['backward']===1;
        $w=$s['len']+3.0+$s['len']*(min(1.5,$s['off']/max(1.0,$corridor))*0.18);
        $base=['kind'=>'airway','airway'=>$s['airway'],'distanceNm'=>$s['len']];
        if($allowF)$graph[$s['from']][]=array_merge($base,['from'=>$s['from'],'to'=>$s['to'],'weight'=>$w,'directionFallback'=>false]);
        else $graph[$s['from']][]=array_merge($base,['from'=>$s['from'],'to'=>$s['to'],'weight'=>$w+1500.0,'directionFallback'=>true]);
        if($allowB)$graph[$s['to']][]=array_merge($base,['from'=>$s['to'],'to'=>$s['from'],'weight'=>$w,'directionFallback'=>false]);
        else $graph[$s['to']][]=array_merge($base,['from'=>$s['to'],'to'=>$s['from'],'weight'=>$w+1500.0,'directionFallback'=>true]);
    }

    $bridgeCandidates=ycAr2AddDctBridges($graph,$coords,$metrics,$direct);
    $terminalRadius=max(175.0,min(310.0,$direct*0.30));$entry=[];$exit=[];
    foreach($coords as $id=>$c){
        if(!isset($metrics[$id])) continue;
        $d1=ycAr2Nm($dep['lat'],$dep['lon'],$c['lat'],$c['lon']);$d2=ycAr2Nm($arr['lat'],$arr['lon'],$c['lat'],$c['lon']);$off=$metrics[$id]['off'];
        if($d1<=$terminalRadius)$entry[]=['id'=>$id,'d'=>$d1,'score'=>$d1+$off*.30];
        if($d2<=$terminalRadius)$exit[]=['id'=>$id,'d'=>$d2,'score'=>$d2+$off*.30];
    }
    usort($entry,fn($a,$b)=>$a['score']<=>$b['score']);usort($exit,fn($a,$b)=>$a['score']<=>$b['score']);
    $entry=array_slice($entry,0,36);$exit=array_slice($exit,0,36);
    $GLOBALS['ycAr2Stats']['entries']=count($entry);$GLOBALS['ycAr2Stats']['exits']=count($exit);$GLOBALS['ycAr2Stats']['bridges']=$bridgeCandidates;
    if(!$entry||!$exit)return ycAr2Fail('no-terminal-airway-candidates');

    $goalPenalty=[];foreach($exit as $g)$goalPenalty[$g['id']]=$g['d']*1.08;
    $dist=[];$prev=[];$pq=new SplPriorityQueue();$pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    foreach($entry as $e){$cost=$e['d']*1.08;if($cost<($dist[$e['id']]??INF)){$dist[$e['id']]=$cost;$pq->insert($e['id'],-$cost);}}
    $bestGoal=null;$bestTotal=INF;$visited=0;
    while(!$pq->isEmpty()&&$visited<180000){
        $cur=$pq->extract();$node=(string)$cur['data'];$cost=-(float)$cur['priority'];
        if($cost>($dist[$node]??INF)+0.0001)continue;$visited++;
        if($cost>$bestTotal)break;
        if(isset($goalPenalty[$node])){$total=$cost+$goalPenalty[$node];if($total<$bestTotal){$bestTotal=$total;$bestGoal=$node;}}
        foreach($graph[$node]??[] as $edge){$next=$edge['to'];$nc=$cost+(float)$edge['weight'];if($nc+0.0001<($dist[$next]??INF)){$dist[$next]=$nc;$prev[$next]=[$node,$edge];$pq->insert($next,-$nc);}}
    }
    $GLOBALS['ycAr2Stats']['visited']=$visited;
    if($bestGoal===null)return ycAr2Fail('no-graph-path');

    $edges=[];$node=$bestGoal;
    while(isset($prev[$node])){[$pn,$edge]=$prev[$node];array_unshift($edges,$edge);$node=$pn;if(count($edges)>1000)return ycAr2Fail('path-too-long');}
    $start=$node;if(!$edges)return ycAr2Fail('empty-path');
    $built=ycAr2EdgesToRoute($edges,$start);
    if($built['route']===''||strlen($built['route'])>1800)return ycAr2Fail('route-string-invalid');
    if($built['bridges']>10||$built['bridgeNm']>$direct*0.48)return ycAr2Fail('excessive-dct');
    if($built['directionFallbacks']>4)return ycAr2Fail('excessive-direction-fallback');

    $GLOBALS['ycAr2LastReason']='ok';
    return [
        'route'=>$built['route'],'directNm'=>round($direct,1),'graphCostNm'=>round($bestTotal,1),
        'segments'=>count(array_filter($edges,fn($e)=>($e['kind']??'airway')==='airway')),
        'airways'=>$built['airways'],'bridges'=>$built['bridges'],'bridgeNm'=>$built['bridgeNm'],
        'directionFallbacks'=>$built['directionFallbacks'],'bridgeCandidates'=>$bridgeCandidates,'entry'=>$start,'exit'=>$bestGoal
    ];
}

$routeSupplied=trim((string)($_GET['route']??''))!=='';
if(!$routeSupplied){
    $from=strtoupper(trim((string)($_GET['from']??'')));$to=strtoupper(trim((string)($_GET['to']??'')));$fl=max(50,min(600,(int)($_GET['fl']??360)));
    if(preg_match('/^[A-Z0-9]{4}$/',$from)&&preg_match('/^[A-Z0-9]{4}$/',$to)&&$from!==$to){
        try{
            $auto=ycAr2Build(nmsDb(),$from,$to,$fl);
            if($auto&&trim((string)$auto['route'])!==''){
                $_GET['route']=$auto['route'];$_GET['_yc_auto_navdata']='1';
                $mode=((int)$auto['bridges']>0||(int)$auto['directionFallbacks']>0)?'navdata-hybrid':'navdata';
                header('X-YC-Auto-Route: '.$mode);
                header('X-YC-Auto-Route-Segments: '.(int)$auto['segments']);
                header('X-YC-Auto-Route-Airways: '.(int)$auto['airways']);
                header('X-YC-Auto-Route-Bridges: '.(int)$auto['bridges']);
                header('X-YC-Auto-Route-Bridge-NM: '.(float)$auto['bridgeNm']);
                header('X-YC-Auto-Route-Direction-Fallbacks: '.(int)$auto['directionFallbacks']);
            }else{
                header('X-YC-Auto-Route: great-circle');
                header('X-YC-Auto-Route-Reason: '.preg_replace('/[^a-z0-9-]/','',(string)($GLOBALS['ycAr2LastReason']??'unknown')));
                $stats=$GLOBALS['ycAr2Stats']??[];
                if(isset($stats['loaded']))header('X-YC-Auto-Route-Loaded: '.(int)$stats['loaded']);
                if(isset($stats['eligible']))header('X-YC-Auto-Route-Eligible: '.(int)$stats['eligible']);
                if(isset($stats['entries']))header('X-YC-Auto-Route-Entries: '.(int)$stats['entries']);
                if(isset($stats['exits']))header('X-YC-Auto-Route-Exits: '.(int)$stats['exits']);
            }
        }catch(Throwable $e){
            error_log('[briefing-auto-v2] '.$e->getMessage());
            header('X-YC-Auto-Route: great-circle');
            header('X-YC-Auto-Route-Reason: engine-error');
        }
    }
}

require __DIR__ . '/briefing-notam-v2.php';
