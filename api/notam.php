<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('X-YC-API-Resource: notam');

function out(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
    try {
        return new DateTimeImmutable(trim((string)$value) === '' ? 'now' : (string)$value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        out(400, ['ok'=>false,'error'=>'Geçersiz UTC.']);
    }
}

function ident(array $row): string {
    $series = strtoupper(trim((string)($row['series'] ?? '')));
    $number = trim((string)($row['number'] ?? ''));
    $year = trim((string)($row['year'] ?? ''));
    $value = $series . $number;
    if ($value === '') return (string)($row['nms_id'] ?? 'NOTAM');
    if ($year !== '' && !preg_match('/\/\d{2}$/', $value)) $value .= '/' . substr($year, -2);
    return $value;
}

function columns(bool $text = true, bool $geometry = false): string {
    $cols = [
        'n.nms_id','n.series','n.number','n.year','n.notam_type','n.classification','n.affected_fir',
        'n.location','n.icao_location','n.selection_code','n.traffic','n.purpose','n.scope',
        'n.minimum_fl','n.maximum_fl','n.effective_start','n.effective_end','n.effective_end_raw',
        'n.schedule','n.lower_limit','n.upper_limit','n.coordinates_raw','n.radius_nm','n.status','n.last_updated'
    ];
    if ($text) $cols[] = 'n.notam_text';
    if ($geometry) $cols[] = 'ST_AsGeoJSON(n.geometry,6) AS geometry';
    return implode(',', $cols);
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

function temporalState(array $row, DateTimeImmutable $at): string {
    if (($row['status'] ?? '') === 'cancelled') return 'cancelled';
    $ts = $at->getTimestamp();
    $start = $row['effective_start'] ? strtotime((string)$row['effective_start'] . ' UTC') : false;
    $end = $row['effective_end'] ? strtotime((string)$row['effective_end'] . ' UTC') : false;
    if ($start !== false && $start > $ts) return 'future';
    if (strtoupper((string)($row['effective_end_raw'] ?? '')) !== 'PERM' && $end !== false && $end < $ts) return 'expired';
    return 'valid';
}

function addExact(array &$where, array &$params, string $column, string $key, string $value, string $regex): void {
    $value = strtoupper(trim($value));
    if ($value !== '' && preg_match($regex, $value)) {
        $where[] = "$column=:$key";
        $params[$key] = $value;
    }
}

function listAction(PDO $pdo): never {
    $at = utc($_GET['at'] ?? '');
    $state = strtolower((string)($_GET['state'] ?? 'valid'));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $where = ["n.source='FAA_NMS'", "n.environment='production'"];
    $params = [];
    $time = $at->format('Y-m-d H:i:s');

    if ($state === 'valid') {
        $where[] = "n.status<>'cancelled'";
        $where[] = '(n.effective_start IS NULL OR n.effective_start<=:at_start)';
        $where[] = "(UPPER(COALESCE(n.effective_end_raw,''))='PERM' OR n.effective_end IS NULL OR n.effective_end>=:at_end)";
        $params['at_start'] = $time;
        $params['at_end'] = $time;
    } elseif ($state === 'future') {
        $where[] = "n.status<>'cancelled'";
        $where[] = 'n.effective_start>:at_start';
        $params['at_start'] = $time;
    } elseif ($state === 'expired') {
        $where[] = "n.status<>'cancelled'";
        $where[] = "UPPER(COALESCE(n.effective_end_raw,''))<>'PERM'";
        $where[] = 'n.effective_end<:at_end';
        $params['at_end'] = $time;
    } elseif ($state === 'cancelled') {
        $where[] = "n.status='cancelled'";
    }

    addExact($where,$params,"UPPER(COALESCE(n.affected_fir,''))",'fir',(string)($_GET['fir'] ?? ''),'/^[A-Z0-9]{4}$/');
    addExact($where,$params,"UPPER(COALESCE(n.notam_type,''))",'type',(string)($_GET['type'] ?? ''),'/^[A-Z]{1,4}$/');
    addExact($where,$params,"UPPER(COALESCE(n.classification,''))",'classification',(string)($_GET['classification'] ?? ''),'/^[A-Z0-9_ -]{1,40}$/');
    addExact($where,$params,"UPPER(COALESCE(n.scope,''))",'scope',(string)($_GET['scope'] ?? ''),'/^[A-Z]{1,8}$/');
    addExact($where,$params,"UPPER(COALESCE(n.traffic,''))",'traffic',(string)($_GET['traffic'] ?? ''),'/^[A-Z]{1,8}$/');
    addExact($where,$params,"UPPER(COALESCE(n.purpose,''))",'purpose',(string)($_GET['purpose'] ?? ''),'/^[A-Z]{1,12}$/');
    addExact($where,$params,"UPPER(COALESCE(n.selection_code,''))",'selection',(string)($_GET['selection_code'] ?? ''),'/^[A-Z0-9]{1,12}$/');

    $icao = strtoupper(trim((string)($_GET['icao'] ?? '')));
    if (preg_match('/^[A-Z0-9]{4}$/', $icao)) {
        $where[] = "(UPPER(COALESCE(n.icao_location,''))=:icao OR UPPER(COALESCE(n.location,''))=:location)";
        $params['icao'] = $icao;
        $params['location'] = $icao;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(n.nms_id LIKE :q1 OR n.notam_text LIKE :q2)';
        $params['q1'] = $params['q2'] = '%' . $q . '%';
    }

    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM notams n WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $offset = ($page - 1) * $limit;
    $order = match ((string)($_GET['sort'] ?? 'updated_desc')) {
        'start_asc' => 'n.effective_start ASC',
        'start_desc' => 'n.effective_start DESC',
        'ident' => 'n.series,n.number',
        default => 'n.last_updated DESC',
    };

    $stmt = $pdo->prepare('SELECT ' . columns(true,false) . ' FROM notams n WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch()) {
        $entry = item($row, true);
        $entry['temporalState'] = temporalState($row, $at);
        $items[] = $entry;
    }

    $pages = $total > 0 ? (int)ceil($total / $limit) : 0;
    out(200, [
        'ok'=>true,'source'=>'FAA NMS','state'=>$state,'atUtc'=>$at->format('c'),'items'=>$items,
        'paging'=>[
            'page'=>$page,'limit'=>$limit,'returned'=>count($items),'total'=>$total,'pages'=>$pages,
            'hasPrevious'=>$page>1,'hasNext'=>$page<$pages,
        ],
    ]);
}

function filtersAction(PDO $pdo): never {
    $options = [];
    foreach (['type'=>'notam_type','classification'=>'classification','scope'=>'scope','traffic'=>'traffic'] as $key=>$column) {
        $stmt = $pdo->query("SELECT DISTINCT $column value FROM notams WHERE source='FAA_NMS' AND environment='production' AND $column IS NOT NULL AND $column<>'' ORDER BY $column LIMIT 200");
        $options[$key] = array_values(array_filter(array_column($stmt->fetchAll(), 'value')));
    }
    out(200, ['ok'=>true,'source'=>'FAA NMS','options'=>$options]);
}

function detailAction(PDO $pdo): never {
    $id = trim((string)($_GET['id'] ?? ''));
    $stmt = $pdo->prepare('SELECT ' . columns(true,false) . " FROM notams n WHERE n.source='FAA_NMS' AND n.environment='production' AND n.nms_id=:id LIMIT 1");
    $stmt->execute(['id'=>$id]);
    $row = $stmt->fetch();
    if (!$row) out(404, ['ok'=>false,'error'=>'NOTAM bulunamadı.']);
    out(200, ['ok'=>true,'source'=>'FAA NMS','notam'=>item($row,true)]);
}

function normalizeLon(float $lon): float {
    while ($lon < -180.0) $lon += 360.0;
    while ($lon > 180.0) $lon -= 360.0;
    return $lon;
}

function circlePolygon(float $lon, float $lat, float $radiusNm, int $steps = 60): array {
    $earthRadiusNm = 3440.065;
    $angularDistance = max(0.0, $radiusNm) / $earthRadiusNm;
    $latRad = deg2rad($lat);
    $lonRad = deg2rad($lon);
    $ring = [];
    for ($i = 0; $i <= $steps; $i++) {
        $bearing = deg2rad(($i / $steps) * 360.0);
        $sinLat2 = sin($latRad) * cos($angularDistance) + cos($latRad) * sin($angularDistance) * cos($bearing);
        $lat2 = asin(max(-1.0, min(1.0, $sinLat2)));
        $lon2 = $lonRad + atan2(
            sin($bearing) * sin($angularDistance) * cos($latRad),
            cos($angularDistance) - sin($latRad) * sin($lat2)
        );
        $ring[] = [round(normalizeLon(rad2deg($lon2)),6), round(rad2deg($lat2),6)];
    }
    return ['type'=>'Polygon','coordinates'=>[$ring]];
}

function coordinateRegex(): string {
    return '/(?:[0-9]{6}(?:\.[0-9]+)?[NS][0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][0-9]{5}(?:\.[0-9]+)?[EW])/i';
}

function parseCoordinate(string $token): ?array {
    $token = strtoupper(trim($token));
    if (preg_match('/^([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2}(?:\.[0-9]+)?)([EW])$/', $token, $m)) {
        $lat = (float)$m[1] + (float)$m[2] / 60.0;
        $lon = (float)$m[4] + (float)$m[5] / 60.0;
        if ($m[3] === 'S') $lat *= -1;
        if ($m[6] === 'W') $lon *= -1;
        return [$lon,$lat];
    }
    if (preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([EW])$/', $token, $m)) {
        $lat = (float)$m[1] + (float)$m[2] / 60.0 + (float)$m[3] / 3600.0;
        $lon = (float)$m[5] + (float)$m[6] / 60.0 + (float)$m[7] / 3600.0;
        if ($m[4] === 'S') $lat *= -1;
        if ($m[8] === 'W') $lon *= -1;
        return [$lon,$lat];
    }
    return null;
}

function extractCoordinates(?string $text, bool $dedupe = true): array {
    $text = strtoupper(trim((string)$text));
    if ($text === '' || !preg_match_all(coordinateRegex(), $text, $matches)) return [];
    $coords = [];
    $seen = [];
    foreach ($matches[0] as $token) {
        $coord = parseCoordinate((string)$token);
        if ($coord === null) continue;
        if (!$dedupe) { $coords[] = $coord; continue; }
        $key = sprintf('%.6F,%.6F', $coord[0], $coord[1]);
        if (!isset($seen[$key])) { $seen[$key] = true; $coords[] = $coord; }
    }
    return $coords;
}

function polygonFromCoordinates(array $coords): ?array {
    if (count($coords) < 3) return null;
    $ring = array_values($coords);
    $first = $ring[0];
    $last = $ring[count($ring)-1];
    if (abs((float)$first[0]-(float)$last[0]) > 0.000001 || abs((float)$first[1]-(float)$last[1]) > 0.000001) $ring[] = $first;
    return ['type'=>'Polygon','coordinates'=>[$ring]];
}

function radiusToNm(float $value, string $unit): ?float {
    if ($value <= 0.0) return null;
    return match (strtoupper(trim($unit))) {
        'NM'=>$value,
        'KM'=>$value/1.852,
        'M'=>$value/1852.0,
        default=>null,
    };
}

function destinationPoint(float $lon, float $lat, float $bearingDeg, float $distanceNm): array {
    $earthRadiusNm = 3440.065;
    $distance = max(0.0,$distanceNm)/$earthRadiusNm;
    $bearing = deg2rad($bearingDeg);
    $lat1 = deg2rad($lat);
    $lon1 = deg2rad($lon);
    $sinLat2 = sin($lat1)*cos($distance) + cos($lat1)*sin($distance)*cos($bearing);
    $lat2 = asin(max(-1.0,min(1.0,$sinLat2)));
    $lon2 = $lon1 + atan2(sin($bearing)*sin($distance)*cos($lat1), cos($distance)-sin($lat1)*sin($lat2));
    return [round(normalizeLon(rad2deg($lon2)),6),round(rad2deg($lat2),6)];
}

function initialBearing(array $from, array $to): float {
    $lat1 = deg2rad((float)$from[1]);
    $lat2 = deg2rad((float)$to[1]);
    $dLon = deg2rad((float)$to[0]-(float)$from[0]);
    $y = sin($dLon)*cos($lat2);
    $x = cos($lat1)*sin($lat2)-sin($lat1)*cos($lat2)*cos($dLon);
    return fmod(rad2deg(atan2($y,$x))+360.0,360.0);
}

function corridorGeometry(array $coords, float $halfWidthNm): ?array {
    if (count($coords) < 2 || $halfWidthNm <= 0.0) return null;
    $polygons = [];
    for ($i=0; $i<count($coords)-1; $i++) {
        $a=$coords[$i]; $b=$coords[$i+1];
        if (abs((float)$a[0]-(float)$b[0])<0.000001 && abs((float)$a[1]-(float)$b[1])<0.000001) continue;
        $bearing=initialBearing($a,$b);
        $aLeft=destinationPoint((float)$a[0],(float)$a[1],$bearing-90,$halfWidthNm);
        $bLeft=destinationPoint((float)$b[0],(float)$b[1],$bearing-90,$halfWidthNm);
        $bRight=destinationPoint((float)$b[0],(float)$b[1],$bearing+90,$halfWidthNm);
        $aRight=destinationPoint((float)$a[0],(float)$a[1],$bearing+90,$halfWidthNm);
        $polygons[]=[[$aLeft,$bLeft,$bRight,$aRight,$aLeft]];
    }
    if (!$polygons) return null;
    return count($polygons)===1 ? ['type'=>'Polygon','coordinates'=>$polygons[0]] : ['type'=>'MultiPolygon','coordinates'=>$polygons];
}

function qSubject(array $row): string {
    $q = strtoupper(trim((string)($row['selection_code'] ?? '')));
    return preg_match('/^Q([A-Z]{2})[A-Z]{2}$/',$q,$m) ? $m[1] : '';
}

function semantic(array $row): array {
    $subject = qSubject($row);
    $class = match ($subject) {
        'RD','RP','RR','RT'=>'RESTRICTED_AIRSPACE',
        'WY'=>'AERIAL_SURVEY',
        'WE'=>'EXERCISE',
        'WF'=>'AIR_REFUELING',
        'WM'=>'FIRING',
        'WU'=>'UAV_ACTIVITY',
        'WG','WL','WP','WT'=>'AERIAL_SPORT_ACTIVITY',
        'OB'=>'OBSTACLE',
        'AC'=>'CONTROLLED_AIRSPACE',
        'MR'=>'RUNWAY',
        default=>'OTHER',
    };
    $group = match ($subject) {
        'WG','WL','WP','WT'=>'AERIAL_SPORT',
        'RD','RP','RR','RT'=>'RESTRICTED_AIRSPACE',
        'WY'=>'AERIAL_SURVEY',
        'WE','WF','WM','WU'=>'TRAINING_MILITARY',
        default=>'OTHER',
    };
    return ['q_subject'=>$subject,'semantic_class'=>$class,'display_group'=>$group];
}

function spatialTextSegment(string $text): ?string {
    foreach ([
        '/\bWI(?:THIN)?\s+AREA\b\s*:?\s*/i',
        '/\bAREA\s+BOUNDED\s+BY\b\s*:?\s*/i',
        '/\bBOUNDED\s+BY\b\s*:?\s*/i',
        '/\bBOUNDARY\b\s*:?\s*/i',
        '/\bLATERAL\s+LIMITS?\b\s*:?\s*/i',
        '/\bAREA\b\s*:?\s*/i',
    ] as $pattern) {
        if (!preg_match($pattern,$text,$m,PREG_OFFSET_CAPTURE)) continue;
        $offset=(int)$m[0][1]+strlen((string)$m[0][0]);
        $segment=substr($text,$offset);
        if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b)/i',$segment,$stop,PREG_OFFSET_CAPTURE)) $segment=substr($segment,0,(int)$stop[0][1]);
        return $segment;
    }
    return null;
}

function explicitPolygon(array $row): ?array {
    $text=(string)($row['notam_text'] ?? '');
    $segment=spatialTextSegment($text);
    if ($segment===null) {
        $s=semantic($row);
        $area=in_array($s['semantic_class'],['RESTRICTED_AIRSPACE','AERIAL_SURVEY','EXERCISE','AIR_REFUELING','FIRING','UAV_ACTIVITY','AERIAL_SPORT_ACTIVITY','CONTROLLED_AIRSPACE'],true);
        if ($area) {
            foreach (['/\bWI\s*:\s*/i','/\bCOORDINATES?\s*:\s*/i'] as $pattern) {
                if (!preg_match($pattern,$text,$m,PREG_OFFSET_CAPTURE)) continue;
                $offset=(int)$m[0][1]+strlen((string)$m[0][0]);
                $segment=substr($text,$offset);
                if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b|VERTICAL\s+LIMITS?\b)/i',$segment,$stop,PREG_OFFSET_CAPTURE)) $segment=substr($segment,0,(int)$stop[0][1]);
                break;
            }
        }
    }
    if ($segment===null) return null;
    $geometry=polygonFromCoordinates(extractCoordinates($segment,true));
    if ($geometry===null) return null;
    return ['geometry'=>$geometry,'source'=>'e-text-polygon','render_type'=>'AREA','confidence'=>'EXPLICIT','explicit_radius_nm'=>null];
}

function explicitCircle(array $row): ?array {
    $text=strtoupper((string)($row['notam_text'] ?? ''));
    if ($text==='' || stripos($text,'RADIUS')===false) return null;
    $coord='(?:[0-9]{6}(?:\.[0-9]+)?[NS][0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][0-9]{5}(?:\.[0-9]+)?[EW])';
    $patterns=[
        '/('.$coord.').{0,180}?\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\b/is',
        '/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+RADIUS.{0,180}?('.$coord.')/is',
        '/\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M).{0,180}?('.$coord.')/is',
    ];
    foreach ($patterns as $i=>$pattern) {
        if (!preg_match($pattern,$text,$m)) continue;
        if ($i===0) { $token=$m[1]; $value=(float)$m[2]; $unit=$m[3]; }
        else { $value=(float)$m[1]; $unit=$m[2]; $token=$m[3]; }
        $point=parseCoordinate($token); $radius=radiusToNm($value,$unit);
        if ($point===null || $radius===null) continue;
        return ['geometry'=>circlePolygon((float)$point[0],(float)$point[1],$radius),'source'=>'e-text-circle','render_type'=>'CIRCLE','confidence'=>'EXPLICIT','explicit_radius_nm'=>round($radius,3)];
    }
    return null;
}

function explicitCorridor(array $row): ?array {
    $text=strtoupper((string)($row['notam_text'] ?? ''));
    if ($text==='' || stripos($text,'EITHER SIDE')===false) return null;
    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+EITHER\s+SIDE\s+OF(?:\s+A)?\s+LINE/i',$text,$m)) return null;
    $width=radiusToNm((float)$m[1],$m[2]);
    if ($width===null) return null;
    $coords=extractCoordinates($text,false);
    $geometry=corridorGeometry($coords,$width);
    if ($geometry===null) return null;
    return ['geometry'=>$geometry,'source'=>'e-text-corridor','render_type'=>'CORRIDOR','confidence'=>'EXPLICIT','explicit_radius_nm'=>round($width,3)];
}

function explicitGeometry(array $row): ?array {
    $polygon=explicitPolygon($row); if ($polygon!==null) return $polygon;
    $corridor=explicitCorridor($row); if ($corridor!==null) return $corridor;
    return explicitCircle($row);
}

function normalizeGeometry(array $geometry): ?array {
    $type=(string)($geometry['type'] ?? '');
    if ($type!=='GeometryCollection') return $type!=='' ? $geometry : null;
    $items=$geometry['geometries'] ?? null;
    if (!is_array($items) || !$items) return null;
    $points=[];$lines=[];$polygons=[];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $g=normalizeGeometry($item); if ($g===null) continue;
        $t=(string)($g['type'] ?? ''); $c=$g['coordinates'] ?? null;
        if ($t==='Point' && is_array($c)) $points[]=$c;
        elseif ($t==='MultiPoint' && is_array($c)) foreach($c as $p) if(is_array($p))$points[]=$p;
        elseif ($t==='LineString' && is_array($c)) $lines[]=$c;
        elseif ($t==='MultiLineString' && is_array($c)) foreach($c as $line) if(is_array($line))$lines[]=$line;
        elseif ($t==='Polygon' && is_array($c)) $polygons[]=$c;
        elseif ($t==='MultiPolygon' && is_array($c)) foreach($c as $poly) if(is_array($poly))$polygons[]=$poly;
    }
    if ($polygons) return count($polygons)===1 ? ['type'=>'Polygon','coordinates'=>$polygons[0]] : ['type'=>'MultiPolygon','coordinates'=>$polygons];
    if ($lines) return count($lines)===1 ? ['type'=>'LineString','coordinates'=>$lines[0]] : ['type'=>'MultiLineString','coordinates'=>$lines];
    if ($points) return count($points)===1 ? ['type'=>'Point','coordinates'=>$points[0]] : ['type'=>'MultiPoint','coordinates'=>$points];
    return null;
}

function pointRenderable(array $row, string $source, array $semantic): bool {
    if (($semantic['semantic_class'] ?? '')==='OBSTACLE') return true;
    $scope=strtoupper((string)($row['scope'] ?? ''));
    return in_array($source,['airport-location','faa-geometry'],true) && str_contains($scope,'A');
}

function category(array $semantic): string {
    return match ($semantic['semantic_class'] ?? 'OTHER') {
        'RESTRICTED_AIRSPACE','AERIAL_SURVEY','EXERCISE','AIR_REFUELING','FIRING','AERIAL_SPORT_ACTIVITY','CONTROLLED_AIRSPACE'=>'AIRSPACE',
        'UAV_ACTIVITY'=>'UAV','OBSTACLE'=>'OBSTACLE','RUNWAY'=>'RWY',default=>'GENERAL',
    };
}

function feature(array $row): ?array {
    $geometry=is_array($row['geometry'] ?? null) ? $row['geometry'] : json_decode((string)($row['geometry'] ?? ''),true);
    if (!is_array($geometry)) return null;
    $geometry=normalizeGeometry($geometry); if ($geometry===null) return null;

    $source=(string)($row['geometry_source'] ?? 'faa-geometry');
    $semantic=semantic($row);
    $explicit=null;
    $type=(string)($geometry['type'] ?? '');

    if (in_array($type,['Point','MultiPoint'],true)) {
        $explicit=explicitGeometry($row);
        if ($explicit!==null) {
            $geometry=$explicit['geometry'];
            $source=$explicit['source'];
            $type=(string)$geometry['type'];
        } else {
            if (($semantic['semantic_class'] ?? '')==='OBSTACLE') {
                $coords=extractCoordinates((string)($row['notam_text'] ?? ''),true);
                if ($coords) {
                    $geometry=['type'=>'Point','coordinates'=>[(float)$coords[0][0],(float)$coords[0][1]]];
                    $source='e-text-point';
                    $type='Point';
                }
            }
            if (!pointRenderable($row,$source,$semantic)) return null;
        }
    }

    $renderType=match($type) {
        'Polygon','MultiPolygon'=>$explicit['render_type'] ?? 'AREA',
        'LineString','MultiLineString'=>'LINE',
        'Point','MultiPoint'=>$source==='airport-location' ? 'ENTITY' : 'POINT',
        default=>'NONE',
    };
    $accuracy=match($source) {
        'faa-geometry'=>'authoritative FAA geometry',
        'e-text-polygon'=>'explicit NOTAM boundary',
        'e-text-circle'=>'explicit NOTAM circle',
        'e-text-corridor'=>'explicit NOTAM corridor',
        'e-text-point'=>'explicit NOTAM point',
        'qline-coordinate'=>'coordinate point fallback',
        'airport-location'=>'airport entity location',
        default=>'derived',
    };

    return [
        'type'=>'Feature',
        'id'=>'n-' . (string)$row['nms_id'],
        'geometry'=>$geometry,
        'properties'=>[
            'layer'=>'notam','nms_id'=>(string)$row['nms_id'],'ident'=>ident($row),
            'classification'=>$row['classification'] ?? null,'location'=>$row['location'] ?? null,'icao_location'=>$row['icao_location'] ?? null,
            'selection_code'=>$row['selection_code'] ?? null,'effective_start'=>$row['effective_start'] ?? null,
            'effective_end'=>$row['effective_end'] ?? null,'effective_end_raw'=>$row['effective_end_raw'] ?? null,
            'lower_limit'=>$row['lower_limit'] ?? null,'upper_limit'=>$row['upper_limit'] ?? null,
            'qline_radius_nm'=>isset($row['radius_nm']) && is_numeric((string)$row['radius_nm']) ? (float)$row['radius_nm'] : null,
            'explicit_radius_nm'=>$explicit['explicit_radius_nm'] ?? null,
            'q_subject'=>$semantic['q_subject'],'semantic_class'=>$semantic['semantic_class'],'display_group'=>$semantic['display_group'],
            'category'=>category($semantic),'map_render_type'=>$renderType,'geometry_source'=>$source,'geometry_accuracy'=>$accuracy,
        ],
    ];
}

function fingerprint(array $feature): string {
    $p=$feature['properties'] ?? [];
    return hash('sha256',json_encode([$p['ident'] ?? '',$p['effective_start'] ?? '',$p['effective_end_raw'] ?? ($p['effective_end'] ?? ''),$feature['geometry'] ?? null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function bboxSql(float $west,float $south,float $east,float $north,array &$params): string {
    if ($west <= $east) {
        $params['bbox']=sprintf('POLYGON((%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F))',$west,$south,$east,$south,$east,$north,$west,$north,$west,$south);
        return 'MBRIntersects(n.geometry,ST_GeomFromText(:bbox))';
    }
    $params['bbox1']=sprintf('POLYGON((%.8F %.8F,180 %.8F,180 %.8F,%.8F %.8F,%.8F %.8F))',$west,$south,$south,$north,$west,$north,$west,$south);
    $params['bbox2']=sprintf('POLYGON((-180 %.8F,%.8F %.8F,%.8F %.8F,-180 %.8F,-180 %.8F))',$south,$east,$south,$east,$north,$north,$south);
    return '(MBRIntersects(n.geometry,ST_GeomFromText(:bbox1)) OR MBRIntersects(n.geometry,ST_GeomFromText(:bbox2)))';
}

function validWhere(): string {
    return "n.source='FAA_NMS' AND n.environment='production' AND n.status<>'cancelled' AND (n.effective_start IS NULL OR n.effective_start<=:at_start) AND (UPPER(COALESCE(n.effective_end_raw,''))='PERM' OR n.effective_end IS NULL OR n.effective_end>=:at_end)";
}

function addFeature(array $row,array &$features,array &$seenIds,array &$seenFeatures): void {
    $id=(string)($row['nms_id'] ?? '');
    if ($id!=='' && isset($seenIds[$id])) return;
    $f=feature($row); if ($f===null) return;
    $hash=fingerprint($f); if(isset($seenFeatures[$hash]))return;
    if($id!=='')$seenIds[$id]=true; $seenFeatures[$hash]=true; $features[]=$f;
}

function mapAction(PDO $pdo): never {
    foreach (['west','south','east','north'] as $key) if (!is_numeric($_GET[$key] ?? null)) out(400,['ok'=>false,'error'=>'bbox gerekli.']);
    $zoom=max(0,min(18,(int)($_GET['z'] ?? 5)));
    $west=normalizeLon((float)$_GET['west']); $east=normalizeLon((float)$_GET['east']);
    $south=max(-85.0,min(85.0,(float)$_GET['south'])); $north=max(-85.0,min(85.0,(float)$_GET['north']));
    if($south>$north)[$south,$north]=[$north,$south];
    $at=utc($_GET['at'] ?? '');
    if($zoom<4) out(200,['ok'=>true,'source'=>'FAA NMS','atUtc'=>$at->format('c'),'data'=>['type'=>'FeatureCollection','features'=>[]],'counts'=>['notam'=>0],'truncated'=>false]);

    header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
    $time=$at->format('Y-m-d H:i:s');
    $features=[];$seenIds=[];$seenFeatures=[];$truncated=false;

    $params=['at_start'=>$time,'at_end'=>$time];
    $bbox=bboxSql($west,$south,$east,$north,$params);
    $sql='SELECT '.columns(true,true).",'faa-geometry' AS geometry_source FROM notams n WHERE ".validWhere()." AND n.geometry IS NOT NULL AND $bbox ORDER BY n.effective_start DESC LIMIT 5000";
    $stmt=$pdo->prepare($sql); $stmt->execute($params);
    while($row=$stmt->fetch()) addFeature($row,$features,$seenIds,$seenFeatures);
    if($stmt->rowCount()>=5000)$truncated=true;

    try {
        $lonWhere=$west<=$east ? 'lon BETWEEN :west AND :east' : '(lon>=:west OR lon<=:east)';
        $ap=$pdo->prepare("SELECT ident,lat,lon FROM nav_points WHERE kind='airport' AND lat BETWEEN :south AND :north AND $lonWhere LIMIT 2500");
        $ap->execute(['south'=>$south,'north'=>$north,'west'=>$west,'east'=>$east]);
        $airports=[];
        while($r=$ap->fetch()){$id=strtoupper(trim((string)$r['ident']));if($id!=='')$airports[$id]=['lon'=>(float)$r['lon'],'lat'=>(float)$r['lat']];}
        if($airports){
            foreach(array_chunk(array_keys($airports),400) as$chunk){
                $marks=implode(',',array_fill(0,count($chunk),'?'));
                $q=$pdo->prepare('SELECT '.columns(true,false).' FROM notams n WHERE '.str_replace([':at_start',':at_end'],['?','?'],validWhere())." AND n.geometry IS NULL AND (UPPER(COALESCE(n.icao_location,'')) IN ($marks) OR UPPER(COALESCE(n.location,'')) IN ($marks)) ORDER BY n.effective_start DESC LIMIT 5000");
                $values=[$time,$time,...$chunk,...$chunk]; $q->execute($values);
                while($row=$q->fetch()){
                    $anchor=strtoupper(trim((string)($row['icao_location'] ?: $row['location']))); $point=$airports[$anchor] ?? null; if(!$point)continue;
                    $row['geometry']=['type'=>'Point','coordinates'=>[$point['lon'],$point['lat']]]; $row['geometry_source']='airport-location';
                    addFeature($row,$features,$seenIds,$seenFeatures);
                }
            }
        }
    } catch(Throwable $e){ error_log('[notam-map-airport] '.$e->getMessage()); }

    try {
        $coordToken="REGEXP_SUBSTR(UPPER(COALESCE(n.coordinates_raw,'')),'([0-9]{6}[NS][0-9]{7}[EW]|[0-9]{4}[NS][0-9]{5}[EW])')";
        $lonWhere=$west<=$east ? 'q.lon BETWEEN :west AND :east' : '(q.lon>=:west OR q.lon<=:east)';
        $sql='SELECT q.* FROM (SELECT p.*,CASE WHEN LENGTH(p.coord_token)=11 THEN (CAST(SUBSTRING(p.coord_token,1,2) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,3,2) AS DECIMAL(10,6))/60)*IF(SUBSTRING(p.coord_token,5,1)=\'S\',-1,1) WHEN LENGTH(p.coord_token)=15 THEN (CAST(SUBSTRING(p.coord_token,1,2) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,3,2) AS DECIMAL(10,6))/60+CAST(SUBSTRING(p.coord_token,5,2) AS DECIMAL(10,6))/3600)*IF(SUBSTRING(p.coord_token,7,1)=\'S\',-1,1) END lat,CASE WHEN LENGTH(p.coord_token)=11 THEN (CAST(SUBSTRING(p.coord_token,6,3) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,9,2) AS DECIMAL(10,6))/60)*IF(SUBSTRING(p.coord_token,11,1)=\'W\',-1,1) WHEN LENGTH(p.coord_token)=15 THEN (CAST(SUBSTRING(p.coord_token,8,3) AS DECIMAL(10,6))+CAST(SUBSTRING(p.coord_token,11,2) AS DECIMAL(10,6))/60+CAST(SUBSTRING(p.coord_token,13,2) AS DECIMAL(10,6))/3600)*IF(SUBSTRING(p.coord_token,15,1)=\'W\',-1,1) END lon FROM (SELECT '.columns(true,false).','.$coordToken.' coord_token FROM notams n WHERE '.validWhere().' AND n.geometry IS NULL) p WHERE p.coord_token IS NOT NULL AND p.coord_token<>\'\') q WHERE q.lat BETWEEN :south AND :north AND '.$lonWhere.' ORDER BY q.effective_start DESC LIMIT 5000';
        $q=$pdo->prepare($sql);$q->execute(['at_start'=>$time,'at_end'=>$time,'south'=>$south,'north'=>$north,'west'=>$west,'east'=>$east]);
        while($row=$q->fetch()){
            $row['geometry']=['type'=>'Point','coordinates'=>[(float)$row['lon'],(float)$row['lat']]];$row['geometry_source']='qline-coordinate';
            addFeature($row,$features,$seenIds,$seenFeatures);
        }
    } catch(Throwable $e){ error_log('[notam-map-qline] '.$e->getMessage()); }

    if(count($features)>=5000)$truncated=true;
    out(200,[
        'ok'=>true,'source'=>'FAA NMS','atUtc'=>$at->format('c'),
        'data'=>['type'=>'FeatureCollection','features'=>$features],
        'counts'=>['notam'=>count($features)],'total'=>count($features),'truncated'=>$truncated,
    ]);
}

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        header('Allow: GET');
        out(405,['ok'=>false,'error'=>'HTTP method not allowed.']);
    }
    $pdo=db();
    $action=strtolower((string)($_GET['action'] ?? 'list'));
    if($action==='list')listAction($pdo);
    if($action==='filters')filtersAction($pdo);
    if($action==='detail')detailAction($pdo);
    if($action==='map')mapAction($pdo);
    if($action==='health')out(200,['ok'=>true,'resource'=>'notam','records'=>(int)$pdo->query("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment='production'")->fetchColumn()]);
    out(400,['ok'=>false,'error'=>'Bilinmeyen action.']);
} catch(Throwable $e) {
    error_log('[notam] ' . $e->getMessage());
    out(500,['ok'=>false,'error'=>'NOTAM isteği işlenemedi.']);
}
