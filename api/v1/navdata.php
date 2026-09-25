<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/store.php';

ycApiV1Headers('public, max-age=30, stale-while-revalidate=60');
ycApiV1Method('GET');

function navEmptyCollection(): array {
    return ['type' => 'FeatureCollection', 'features' => []];
}

function navClamp(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function navNormalizeLon(float $lon): float {
    while ($lon > 180) $lon -= 360;
    while ($lon < -180) $lon += 360;
    return $lon;
}

function navBboxSql(float $west, float $south, float $east, float $north, array &$params): string {
    if ($west <= $east) {
        $params['bbox'] = sprintf(
            'POLYGON((%.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F))',
            $west, $south, $east, $south, $east, $north, $west, $north, $west, $south
        );
        return 'MBRIntersects(%s, ST_GeomFromText(:bbox))';
    }

    $params['bbox1'] = sprintf(
        'POLYGON((%.8F %.8F, 180 %.8F, 180 %.8F, %.8F %.8F, %.8F %.8F))',
        $west, $south, $south, $north, $west, $north, $west, $south
    );
    $params['bbox2'] = sprintf(
        'POLYGON((-180 %.8F, %.8F %.8F, %.8F %.8F, -180 %.8F, -180 %.8F))',
        $south, $east, $south, $east, $north, $north, $south
    );
    return '(MBRIntersects(%s, ST_GeomFromText(:bbox1)) OR MBRIntersects(%s, ST_GeomFromText(:bbox2)))';
}

function navPointFeature(array $row): array {
    $kind = $row['kind'] === 'designatedpoint' ? 'waypoint' : $row['kind'];
    return [
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$row['lon'], (float)$row['lat']]],
        'properties' => [
            'layer' => $kind,
            'id' => (int)$row['id'],
            'ident' => $row['ident'],
            'name' => $row['name'],
            'type_code' => $row['type_code'],
            'city' => $row['city'],
            'iata' => $row['iata'],
            'elevation_ft' => $row['elevation_ft'] !== null ? (int)$row['elevation_ft'] : null,
            'frequency' => $row['frequency_text'],
            'channel' => $row['channel'],
            'status' => $row['provider_status'],
        ],
    ];
}

function navRouteFeature(array $row): ?array {
    $geometry = json_decode((string)($row['geometry'] ?? ''), true);
    if (!is_array($geometry)) return null;
    return [
        'type' => 'Feature',
        'geometry' => $geometry,
        'properties' => [
            'layer' => $row['route_type'],
            'id' => (int)$row['route_id'],
            'ident' => $row['ident'],
            'from_ident' => $row['from_ident'],
            'to_ident' => $row['to_ident'],
            'forward' => (bool)$row['forward'],
            'backward' => (bool)$row['backward'],
            'lower_text' => $row['lower_text'],
            'upper_text' => $row['upper_text'],
            'upper_unlimited' => (bool)$row['upper_unlimited'],
        ],
    ];
}

function navAirspaceFeature(array $row): ?array {
    $geometry = json_decode((string)($row['geometry'] ?? ''), true);
    if (!is_array($geometry)) return null;
    return [
        'type' => 'Feature',
        'geometry' => $geometry,
        'properties' => [
            'layer' => 'airspace',
            'id' => (int)$row['airspace_id'],
            'ident' => $row['ident'],
            'name' => $row['name'],
            'type_code' => $row['type_code'],
            'local_type' => $row['local_type'],
            'usage_code' => $row['usage_code'],
            'control_type' => $row['control_type'],
            'activity' => $row['activity'],
            'lower_text' => $row['lower_text'],
            'upper_text' => $row['upper_text'],
            'upper_unlimited' => (bool)$row['upper_unlimited'],
        ],
    ];
}

function navAirportItem(array $row): array {
    return [
        'id' => (int)$row['id'],
        'icao' => $row['ident'],
        'iata' => $row['iata'] ?: null,
        'name' => $row['name'],
        'city' => $row['city'] ?: null,
        'lat' => (float)$row['lat'],
        'lon' => (float)$row['lon'],
        'elevationFt' => $row['elevation_ft'] !== null ? (int)$row['elevation_ft'] : null,
        'typeCode' => $row['type_code'] ?: null,
        'providerStatus' => $row['provider_status'] ?: null,
    ];
}

try {
    $pdo = nmsDb();
    $action = strtolower(trim((string)($_GET['action'] ?? 'viewport')));

    if ($action === 'health') {
        $tables = ['nav_points','nav_routes','nav_route_segments','nav_route_memberships','nav_route_availability','nav_route_geometry','nav_airspaces','nav_airspace_geometry'];
        $counts = [];
        foreach ($tables as $table) $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'health','counts'=>$counts]);
    }

    if ($action === 'airport-detail') {
        $ident = strtoupper(ycApiV1String($_GET, 'ident', 8));
        if (!preg_match('/^[A-Z0-9]{3,8}$/', $ident)) ycApiV1Respond(400, ['ok'=>false,'error'=>'Geçerli airport ident gerekli.']);
        $stmt = $pdo->prepare("SELECT id,ident,iata,name,city,lat,lon,elevation_ft,type_code,provider_status FROM nav_points WHERE kind='airport' AND (UPPER(ident)=:ident OR UPPER(COALESCE(iata,''))=:iata) ORDER BY (UPPER(ident)=:ord) DESC LIMIT 1");
        $stmt->execute(['ident'=>$ident,'iata'=>$ident,'ord'=>$ident]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) ycApiV1Respond(404, ['ok'=>false,'error'=>'Airport bulunamadı.']);
        ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'airport-detail','airport'=>navAirportItem($row)]);
    }

    if ($action === 'airport-near') {
        $lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
        $lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lat === null || $lon === false || $lon === null) ycApiV1Respond(400, ['ok'=>false,'error'=>'lat ve lon gerekli.']);
        $delta = isset($_GET['delta']) && is_numeric($_GET['delta']) ? max(.05, min(10, (float)$_GET['delta'])) : 1.0;
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        $stmt = $pdo->prepare("SELECT id,ident,iata,name,city,lat,lon,elevation_ft,type_code,provider_status FROM nav_points WHERE kind='airport' AND lat BETWEEN :s AND :n AND lon BETWEEN :w AND :e ORDER BY ABS(lat-:lat)+ABS(lon-:lon) LIMIT {$limit}");
        $stmt->execute(['s'=>(float)$lat-$delta,'n'=>(float)$lat+$delta,'w'=>(float)$lon-$delta,'e'=>(float)$lon+$delta,'lat'=>(float)$lat,'lon'=>(float)$lon]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'airport-near','count'=>count($rows),'items'=>array_map('navAirportItem',$rows)]);
    }

    if ($action === 'airport-search') {
        $q = strtoupper(ycApiV1String($_GET, 'q', 80));
        if (strlen($q) < 2) ycApiV1Respond(400, ['ok'=>false,'error'=>'En az 2 karakter gir.']);
        $like = '%' . $q . '%';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        $stmt = $pdo->prepare("SELECT id,ident,iata,name,city,lat,lon,elevation_ft,type_code,provider_status FROM nav_points WHERE kind='airport' AND (UPPER(ident) LIKE :a OR UPPER(COALESCE(iata,'')) LIKE :b OR UPPER(COALESCE(name,'')) LIKE :c OR UPPER(COALESCE(city,'')) LIKE :d) ORDER BY (UPPER(ident)=:ei) DESC,(UPPER(COALESCE(iata,''))=:ea) DESC,ident LIMIT {$limit}");
        $stmt->execute(['a'=>$like,'b'=>$like,'c'=>$like,'d'=>$like,'ei'=>$q,'ea'=>$q]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'airport-search','query'=>$q,'count'=>count($rows),'items'=>array_map('navAirportItem',$rows)]);
    }

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? ''));
        $qlen = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
        if ($q === '' || $qlen < 2) ycApiV1Respond(400, ['ok'=>false,'error'=>'En az 2 karakter gir.']);
        $like = '%' . $q . '%';
        $results = [];
        $stmt = $pdo->prepare('SELECT id,kind,ident,name,lat,lon FROM nav_points WHERE ident LIKE :q1 OR name LIKE :q2 ORDER BY (ident=:exact) DESC,kind,ident LIMIT 12');
        $stmt->execute(['q1'=>$like,'q2'=>$like,'exact'=>$q]);
        foreach ($stmt as $row) $results[] = ['kind'=>$row['kind']==='designatedpoint'?'waypoint':$row['kind'],'id'=>(int)$row['id'],'ident'=>$row['ident'],'name'=>$row['name'],'lon'=>(float)$row['lon'],'lat'=>(float)$row['lat']];
        $stmt = $pdo->prepare('SELECT id,ident,type FROM nav_routes WHERE ident LIKE :q ORDER BY (ident=:exact) DESC,type,ident LIMIT 8');
        $stmt->execute(['q'=>$like,'exact'=>$q]);
        foreach ($stmt as $row) $results[] = ['kind'=>$row['type'],'id'=>(int)$row['id'],'ident'=>$row['ident'],'name'=>null,'lon'=>null,'lat'=>null];
        $stmt = $pdo->prepare('SELECT id,ident,name FROM nav_airspaces WHERE ident LIKE :q1 OR name LIKE :q2 ORDER BY (ident=:exact) DESC,ident LIMIT 8');
        $stmt->execute(['q1'=>$like,'q2'=>$like,'exact'=>$q]);
        foreach ($stmt as $row) $results[] = ['kind'=>'airspace','id'=>(int)$row['id'],'ident'=>$row['ident'],'name'=>$row['name'],'lon'=>null,'lat'=>null];
        ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'search','results'=>array_slice($results,0,24)]);
    }

    if ($action !== 'viewport') ycApiV1Respond(400, ['ok'=>false,'error'=>'Geçersiz action.']);

    $zoom = isset($_GET['z']) && is_numeric($_GET['z']) ? max(0, min(18, (int)$_GET['z'])) : 5;
    foreach (['west','south','east','north'] as $key) if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) ycApiV1Respond(400, ['ok'=>false,'error'=>"Eksik/geçersiz bbox: {$key}"]);
    $west = navNormalizeLon((float)$_GET['west']);
    $east = navNormalizeLon((float)$_GET['east']);
    $south = navClamp((float)$_GET['south'], -85, 85);
    $north = navClamp((float)$_GET['north'], -85, 85);
    if ($south > $north) [$south,$north] = [$north,$south];

    $requested = array_filter(array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', (string)($_GET['layers'] ?? 'airport,navaid,waypoint,airway,sid,star,airspace'))));
    $allowed = ['airport','navaid','waypoint','airway','sid','star','airspace'];
    $layers = array_values(array_intersect($allowed, $requested));
    $features = [];
    $counts = array_fill_keys($allowed, 0);
    $truncated = false;

    if ($zoom < 5 || !$layers) ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'viewport','zoom'=>$zoom,'data'=>navEmptyCollection(),'counts'=>$counts,'total'=>0,'truncated'=>false]);

    $pointKinds = [];
    if (in_array('airport',$layers,true) && $zoom >= 5) $pointKinds[] = 'airport';
    if (in_array('navaid',$layers,true) && $zoom >= 6) $pointKinds[] = 'navaid';
    if (in_array('waypoint',$layers,true) && $zoom >= 8) $pointKinds[] = 'designatedpoint';
    if ($pointKinds) {
        $whereLon = $west <= $east ? 'lon BETWEEN :west AND :east' : '(lon>=:west OR lon<=:east)';
        $params = ['south'=>$south,'north'=>$north,'west'=>$west,'east'=>$east];
        $holders = [];
        foreach ($pointKinds as $i=>$kind) { $k='kind'.$i; $holders[]=':'.$k; $params[$k]=$kind; }
        $stmt = $pdo->prepare('SELECT id,kind,ident,name,lat,lon,type_code,city,iata,elevation_ft,frequency_text,channel,provider_status FROM nav_points WHERE kind IN ('.implode(',',$holders).') AND lat BETWEEN :south AND :north AND '.$whereLon.' LIMIT 16000');
        $stmt->execute($params);
        $n=0;
        while ($row=$stmt->fetch()) { $f=navPointFeature($row); $features[]=$f; $counts[$f['properties']['layer']]++; $n++; }
        if ($n>=16000) $truncated=true;
    }

    $routeTypes = [];
    if (in_array('airway',$layers,true) && $zoom >= 5) $routeTypes[]='airway';
    if (in_array('sid',$layers,true) && $zoom >= 8) $routeTypes[]='sid';
    if (in_array('star',$layers,true) && $zoom >= 8) $routeTypes[]='star';
    if ($routeTypes) {
        $params=[]; $bbox=sprintf(navBboxSql($west,$south,$east,$north,$params),'rg.geom','rg.geom');
        $holders=[]; foreach($routeTypes as $i=>$type){$k='rtype'.$i;$holders[]=':'.$k;$params[$k]=$type;} $params['zoom']=$zoom;
        $stmt=$pdo->prepare('SELECT rg.id AS geometry_id,rg.from_ident,rg.to_ident,ST_AsGeoJSON(rg.geom,6) AS geometry,r.id AS route_id,r.ident,r.type AS route_type,rm.forward,rm.backward,rm.lower_text,rm.upper_text,rm.upper_unlimited FROM nav_route_geometry rg JOIN nav_route_memberships rm ON rm.segment_id=rg.segment_id JOIN nav_routes r ON r.id=rm.route_id WHERE r.type IN ('.implode(',',$holders).') AND r.suggested_min_zoom<=:zoom AND '.$bbox.' LIMIT 22000');
        $stmt->execute($params);
        $n=0;
        while($row=$stmt->fetch()){if($f=navRouteFeature($row)){$features[]=$f;$counts[$f['properties']['layer']]++;$n++;}}
        if($n>=22000)$truncated=true;
    }

    if (in_array('airspace',$layers,true) && $zoom >= 5) {
        $params=[]; $bbox=sprintf(navBboxSql($west,$south,$east,$north,$params),'ag.geom','ag.geom');
        $stmt=$pdo->prepare('SELECT ag.id AS geometry_id,ag.airspace_id,ag.ident,ST_AsGeoJSON(ag.geom,6) AS geometry,a.name,a.type_code,a.local_type,a.usage_code,a.control_type,a.activity,a.lower_text,a.upper_text,a.upper_unlimited FROM nav_airspace_geometry ag LEFT JOIN nav_airspaces a ON a.id=ag.airspace_id WHERE '.$bbox.' LIMIT 6000');
        $stmt->execute($params);
        $n=0;
        while($row=$stmt->fetch()){if($f=navAirspaceFeature($row)){$features[]=$f;$counts['airspace']++;$n++;}}
        if($n>=6000)$truncated=true;
    }

    ycApiV1Respond(200, ['ok'=>true,'resource'=>'navdata','mode'=>'viewport','zoom'=>$zoom,'bbox'=>compact('west','south','east','north'),'data'=>['type'=>'FeatureCollection','features'=>$features],'counts'=>$counts,'total'=>count($features),'truncated'=>$truncated]);
} catch (Throwable $e) {
    ycApiV1Respond(500, ['ok'=>false,'error'=>'Navdata isteği işlenemedi.']);
}
