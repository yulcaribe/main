<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

const AWC_BASE = 'https://aviationweather.gov/api/data/';
const USER_AGENT = 'YulCaribe-PilotBrief/1.0 (+https://yulcaribe.com)';

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_pilotbrief_v2';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function cacheGet(string $key, int $maxAge): ?array {
    $file = cacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['savedAt'], $data['payload'])) return null;
    $age = time() - (int)$data['savedAt'];
    if ($age < 0 || $age > $maxAge || !is_array($data['payload'])) return null;
    $data['payload']['cache'] = ['hit' => true, 'ageSeconds' => $age];
    return $data['payload'];
}

function cachePut(string $key, array $payload): void {
    $file = cacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    @file_put_contents(
        $file,
        json_encode(['savedAt' => time(), 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function awcGet(string $path, array $query = [], int $timeout = 15): array {
    $url = AWC_BASE . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $attempt = function(bool $verifyPeer) use ($url, $timeout): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json, application/geo+json;q=0.9, */*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return compact('body', 'errno', 'error', 'status');
    };

    $r = $attempt(true);
    if ($r['errno'] !== 0 && stripos((string)$r['error'], 'certificate has expired') !== false) {
        $r = $attempt(false);
    }

    if ($r['status'] === 204) return ['ok' => true, 'status' => 204, 'data' => []];
    if ($r['errno'] !== 0 || $r['body'] === false || $r['status'] < 200 || $r['status'] >= 300) {
        return ['ok' => false, 'status' => $r['status'], 'error' => $r['error'] ?: ('HTTP ' . $r['status']), 'url' => $url];
    }

    $data = json_decode((string)$r['body'], true);
    if ($data === null && trim((string)$r['body']) !== 'null') {
        return ['ok' => false, 'status' => $r['status'], 'error' => 'AWC JSON yanıtı çözülemedi.', 'url' => $url];
    }

    return ['ok' => true, 'status' => $r['status'], 'data' => $data];
}

function rad(float $d): float { return $d * M_PI / 180.0; }
function deg(float $r): float { return $r * 180.0 / M_PI; }

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 3440.065;
    $p1 = rad($lat1); $p2 = rad($lat2);
    $dp = rad($lat2 - $lat1); $dl = rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
}

function greatCircle(float $lat1, float $lon1, float $lat2, float $lon2, int $count): array {
    $p1 = rad($lat1); $l1 = rad($lon1); $p2 = rad($lat2); $l2 = rad($lon2);
    $delta = 2 * asin(sqrt(sin(($p2-$p1)/2)**2 + cos($p1)*cos($p2)*sin(($l2-$l1)/2)**2));
    if ($delta < 1e-9) return [[$lat1, $lon1], [$lat2, $lon2]];

    $pts = [];
    for ($i = 0; $i < $count; $i++) {
        $f = $i / ($count - 1);
        $a = sin((1-$f)*$delta) / sin($delta);
        $b = sin($f*$delta) / sin($delta);
        $x = $a*cos($p1)*cos($l1) + $b*cos($p2)*cos($l2);
        $y = $a*cos($p1)*sin($l1) + $b*cos($p2)*sin($l2);
        $z = $a*sin($p1) + $b*sin($p2);
        $lat = atan2($z, sqrt($x*$x + $y*$y));
        $lon = atan2($y, $x);
        $pts[] = [round(deg($lat), 5), round(deg($lon), 5)];
    }
    return $pts;
}

function nearestRoute(array $route, float $lat, float $lon): array {
    $best = INF; $idx = 0;
    foreach ($route as $i => $p) {
        $d = haversineNm($lat, $lon, (float)$p[0], (float)$p[1]);
        if ($d < $best) { $best = $d; $idx = $i; }
    }
    $progress = count($route) > 1 ? $idx / (count($route) - 1) : 0.0;
    return [$best, $progress];
}

function buildRoute(array $points): array {
    $route = [];
    $distance = 0.0;
    for ($i = 0; $i < count($points) - 1; $i++) {
        $a = $points[$i]; $b = $points[$i + 1];
        $legDistance = haversineNm((float)$a['lat'], (float)$a['lon'], (float)$b['lat'], (float)$b['lon']);
        $distance += $legDistance;
        $count = max(2, min(140, (int)ceil($legDistance / 18) + 1));
        $leg = greatCircle((float)$a['lat'], (float)$a['lon'], (float)$b['lat'], (float)$b['lon'], $count);
        if ($route && $leg) array_shift($leg);
        array_push($route, ...$leg);
    }
    return [$route, $distance];
}

function sampleRoute(array $route, int $count): array {
    $n = count($route);
    if ($n <= $count) return $route;
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $idx = (int)round(($i / max(1, $count - 1)) * ($n - 1));
        $out[] = $route[$idx];
    }
    return $out;
}

function parseCoordinateToken(string $token): ?array {
    if (preg_match('/^(\d{2})([NS])(\d{3})([EW])$/', $token, $m)) {
        $lat = (float)$m[1] * ($m[2] === 'S' ? -1 : 1);
        $lon = (float)$m[3] * ($m[4] === 'W' ? -1 : 1);
        if (abs($lat) <= 90 && abs($lon) <= 180) return ['lat' => $lat, 'lon' => $lon];
    }
    if (preg_match('/^(\d{2})(\d{2})([NS])(\d{3})(\d{2})([EW])$/', $token, $m)) {
        $lat = ((float)$m[1] + ((float)$m[2] / 60)) * ($m[3] === 'S' ? -1 : 1);
        $lon = ((float)$m[4] + ((float)$m[5] / 60)) * ($m[6] === 'W' ? -1 : 1);
        if (abs($lat) <= 90 && abs($lon) <= 180) return ['lat' => $lat, 'lon' => $lon];
    }
    return null;
}

function parseFlightLevelToken(string $token): ?int {
    if (!preg_match('/^F(?:L)?(\d{2,3})$/', strtoupper($token), $m)) return null;
    $fl=(int)$m[1];
    return ($fl>=0 && $fl<=600) ? $fl : null;
}

function isAirwayDesignator(string $token): bool {
    // ICAO ATS route designator heuristic. Handles L610, N131, G8, UL620,
    // UT39, UM860 etc. Terminal fixes such as FJ823 deliberately do not match.
    return (bool)preg_match('/^(?:[UKS]?[ABGRLMNPHJVWQTYZ])\d{1,4}[A-Z]?$/', $token);
}

function isProcedureDesignator(string $token): bool {
    // Common SID/STAR shape such as EKSEN1K / ETAMP1G. Exact procedure
    // validation belongs to the navdata resolver, not to this lexical parser.
    return (bool)preg_match('/^[A-Z]{3,6}\d[A-Z]$/', $token);
}

function splitRouteSections(string $raw): array {
    $marked=preg_replace('/\bDEST\s+ALTN2\s+ROUTE\b/i', "\n@@ALTN2@@\n", $raw) ?? $raw;
    $marked=preg_replace('/\bDEST\s+ALTN\s+ROUTE\b/i', "\n@@ALTN1@@\n", $marked) ?? $marked;
    $buckets=['main'=>[],'altn1'=>[],'altn2'=>[]];
    $current='main';

    foreach (preg_split('/\R+/', $marked) ?: [] as $line) {
        $line=trim($line);
        if ($line==='') continue;
        if ($line==='@@ALTN1@@') { $current='altn1'; continue; }
        if ($line==='@@ALTN2@@') { $current='altn2'; continue; }
        $buckets[$current][]=$line;
    }

    return [
        'main'=>trim(implode(' ', $buckets['main'])),
        'alternates'=>array_values(array_filter([
            $buckets['altn1'] ? ['name'=>'DEST ALTN ROUTE','raw'=>trim(implode(' ', $buckets['altn1']))] : null,
            $buckets['altn2'] ? ['name'=>'DEST ALTN2 ROUTE','raw'=>trim(implode(' ', $buckets['altn2']))] : null,
        ]))
    ];
}

function parseRouteSection(string $raw, string $from, string $to, bool $isAlternate=false): array {
    $raw=strtoupper(trim($raw));
    $raw=preg_replace('/[\r\n\t]+/', ' ', $raw) ?? $raw;
    $tokens=preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $items=[]; $ignored=[]; $unknown=[];
    $vertical=['initialFL'=>null,'changes'=>[]];
    $procedureHint=null;
    $afterDestination=false;

    foreach ($tokens as $original) {
        $token=trim($original, " \t\n\r\0\x0B()[]{}");
        $token=trim($token, '.');
        if ($token==='') continue;

        if ($token==='SID' || $token==='STAR') {
            $procedureHint=strtolower($token);
            continue;
        }
        if (in_array($token,['IFR','VFR','NAT'],true)) {
            $ignored[]=$token;
            continue;
        }
        if ($token==='DCT') {
            $items[]=['token'=>'DCT','kind'=>'dct'];
            continue;
        }

        $base=$token;
        $levelFL=null;
        if (preg_match('/^(.+)\/F(?:L)?(\d{2,3})$/', $token, $m)) {
            $base=trim($m[1]);
            $levelFL=(int)$m[2];
            if ($levelFL<0 || $levelFL>600) $levelFL=null;
        }
        $token=$base;
        if ($token==='') continue;

        $standaloneFL=parseFlightLevelToken($token);
        if ($standaloneFL!==null) {
            if ($vertical['initialFL']===null) $vertical['initialFL']=$standaloneFL;
            if (!$afterDestination) $items[]=['token'=>$token,'kind'=>'flight_level','fl'=>$standaloneFL];
            continue;
        }

        $item=null;
        if (preg_match('/^([A-Z0-9]{4})R(?:WY)?([0-9]{2}[LCR]?)$/', $token, $m)) {
            $item=['token'=>$token,'kind'=>'airport_runway','airport'=>$m[1],'runway'=>$m[2]];
        } else {
            $coord=parseCoordinateToken($token);
            if ($coord) {
                $item=['token'=>$token,'kind'=>'coordinate','coord'=>$coord];
            } elseif (isProcedureDesignator($token)) {
                $item=['token'=>$token,'kind'=>'procedure','role'=>$procedureHint];
                $procedureHint=null;
            } elseif (isAirwayDesignator($token)) {
                $item=['token'=>$token,'kind'=>'airway'];
            } elseif ($token===$from || $token===$to || ($isAlternate && preg_match('/^[A-Z]{4}$/',$token))) {
                $item=['token'=>$token,'kind'=>'airport','airport'=>$token];
            } elseif (preg_match('/^[A-Z]{5}$/',$token) || preg_match('/^[A-Z]{2}\d{3}$/',$token)) {
                $item=['token'=>$token,'kind'=>'fix'];
            } elseif (preg_match('/^[A-Z]{2,3}$/',$token)) {
                $item=['token'=>$token,'kind'=>'navaid'];
            } else {
                $item=['token'=>$token,'kind'=>'unknown'];
                $unknown[]=$token;
            }
        }

        if ($levelFL!==null) {
            $item['levelFL']=$levelFL;
            $vertical['changes'][]=['at'=>$token,'fl'=>$levelFL];
        }

        // Some OFP formats append the vertical profile after the destination
        // runway, e.g. "... LTFJR06R F260 ETAMP/F240 EMGIM/F180 ...".
        // Those tokens describe descent/profile checkpoints; they must not be
        // appended to the lateral route after the destination.
        if ($afterDestination) {
            if ($levelFL!==null) continue;
            $ignored[]=$token;
            continue;
        }

        $items[]=$item;

        if (
            ($item['kind']==='airport_runway' && ($item['airport']??'')===$to) ||
            ($item['kind']==='airport' && ($item['airport']??'')===$to)
        ) {
            $afterDestination=true;
        }
    }

    // Infer SID/STAR only when the lexical shape is clear. Validation and exact
    // legs remain a resolver/navdata job.
    $navIndexes=[];
    foreach ($items as $i=>$item) {
        if (in_array($item['kind'],['fix','navaid','coordinate'],true)) $navIndexes[]=$i;
    }
    $firstNav=$navIndexes ? min($navIndexes) : null;
    $lastNav=$navIndexes ? max($navIndexes) : null;

    foreach ($items as $i=>&$item) {
        if (($item['kind']??'')!=='procedure' || !empty($item['role'])) continue;
        if ($firstNav!==null && $i<$firstNav) $item['role']='sid';
        elseif ($lastNav!==null && $i>$lastNav) $item['role']='star';
        else $item['role']='procedure';
    }
    unset($item);

    $structure=[
        'departure'=>['airport'=>$from,'runway'=>null,'sid'=>null],
        'enroute'=>[],
        'arrival'=>['airport'=>$to,'runway'=>null,'star'=>null],
        'procedures'=>[]
    ];

    foreach ($items as $item) {
        $kind=$item['kind']??'';
        if ($kind==='airport_runway') {
            if (($item['airport']??'')===$from) $structure['departure']['runway']=$item['runway'];
            elseif (($item['airport']??'')===$to) $structure['arrival']['runway']=$item['runway'];
            continue;
        }
        if ($kind==='procedure') {
            $role=$item['role']??'procedure';
            $structure['procedures'][]=['id'=>$item['token'],'role'=>$role];
            if ($role==='sid' && $structure['departure']['sid']===null) $structure['departure']['sid']=$item['token'];
            elseif ($role==='star' && $structure['arrival']['star']===null) $structure['arrival']['star']=$item['token'];
            else $structure['enroute'][]=['type'=>'procedure','id'=>$item['token'],'role'=>$role];
            continue;
        }
        if (in_array($kind,['fix','navaid','coordinate','airway','dct'],true)) {
            $row=['type'=>$kind,'id'=>$item['token']];
            if (isset($item['levelFL'])) $row['levelFL']=$item['levelFL'];
            if ($kind==='coordinate') $row['coord']=$item['coord'];
            $structure['enroute'][]=$row;
        }
    }

    return [
        'items'=>$items,
        'structure'=>$structure,
        'verticalProfile'=>$vertical,
        'unknown'=>array_values(array_unique($unknown)),
        'ignored'=>array_values(array_unique($ignored))
    ];
}

function parseRouteTokens(string $raw, string $from, string $to): array {
    $sections=splitRouteSections($raw);
    $main=parseRouteSection($sections['main'],$from,$to,false);
    $alternates=[];

    foreach ($sections['alternates'] as $section) {
        $parsed=parseRouteSection((string)$section['raw'],$from,$to,true);
        $airportIds=[];
        foreach ($parsed['items'] as $item) {
            if (($item['kind']??'')==='airport') $airportIds[]=$item['airport'];
            elseif (($item['kind']??'')==='airport_runway') $airportIds[]=$item['airport'];
        }
        $alternates[]=[
            'name'=>$section['name'],
            'raw'=>$section['raw'],
            'departure'=>$airportIds[0]??null,
            'destination'=>$airportIds ? $airportIds[count($airportIds)-1] : null,
            'items'=>$parsed['items'],
            'verticalProfile'=>$parsed['verticalProfile'],
            'unknown'=>$parsed['unknown']
        ];
    }

    return [
        'parsed'=>$main['items'],
        'ignored'=>$main['ignored'],
        'unknown'=>$main['unknown'],
        'structure'=>$main['structure'],
        'verticalProfile'=>$main['verticalProfile'],
        'alternates'=>$alternates
    ];
}

function groupNavRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = strtoupper((string)($row['id'] ?? $row['ident'] ?? ''));
        if ($id === '' || !isset($row['lat'], $row['lon']) || !is_numeric($row['lat']) || !is_numeric($row['lon'])) continue;
        $out[$id][] = $row;
    }
    return $out;
}

function chooseNavCandidate(array $rows, array $directRoute, float $lastProgress, float $maxOffRouteNm): ?array {
    $best = null; $bestScore = INF;
    foreach ($rows as $row) {
        $lat = (float)$row['lat']; $lon = (float)$row['lon'];
        [$offRoute, $progress] = nearestRoute($directRoute, $lat, $lon);
        if ($offRoute > $maxOffRouteNm) continue;
        $backtrackPenalty = ($progress + 0.12 < $lastProgress) ? 700 : 0;
        $score = $offRoute + $backtrackPenalty;
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = ['lat'=>$lat,'lon'=>$lon,'progress'=>$progress,'raw'=>$row];
        }
    }
    return $best;
}

function navDb(): ?PDO {
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($pdo === null) return null;
    if (!extension_loaded('pdo_mysql')) { $pdo=null; return null; }

    $homeRoot=dirname(dirname(dirname(__DIR__)));
    $configPath=$homeRoot.'/data.php';
    if (!is_file($configPath)) { $pdo=null; return null; }

    $cfg=require $configPath;
    if (!is_array($cfg)) { $pdo=null; return null; }
    foreach (['host','port','database','user','password'] as $key) {
        if (!array_key_exists($key,$cfg)) { $pdo=null; return null; }
    }

    try {
        $dsn=sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'],(int)$cfg['port'],$cfg['database']
        );
        $pdo=new PDO($dsn,$cfg['user'],$cfg['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
        return $pdo;
    } catch (Throwable $e) {
        $pdo=null;
        return null;
    }
}

function navFetchPointCandidates(PDO $pdo,array $idents): array {
    $idents=array_values(array_unique(array_filter(array_map(
        fn($v)=>strtoupper(trim((string)$v)),
        $idents
    ))));
    if (!$idents) return [];

    $placeholders=implode(',',array_fill(0,count($idents),'?'));
    $stmt=$pdo->prepare(
        "SELECT id,source_id,kind,ident,name,lat,lon,type_code,frequency_text,channel,provider_status
         FROM nav_points
         WHERE ident IN ($placeholders)"
    );
    $stmt->execute($idents);

    $out=[];
    while ($row=$stmt->fetch()) {
        $id=strtoupper((string)$row['ident']);
        $out[$id][]=$row;
    }
    return $out;
}

function navGeoLines(?string $json): array {
    if ($json===null || trim($json)==='') return [];
    $g=json_decode($json,true);
    if (!is_array($g)) return [];

    $type=$g['type']??'';
    $coords=$g['coordinates']??null;
    if ($type==='LineString' && is_array($coords)) return [$coords];
    if ($type==='MultiLineString' && is_array($coords)) return $coords;

    if ($type==='GeometryCollection' && is_array($g['geometries']??null)) {
        $out=[];
        foreach ($g['geometries'] as $child) {
            if (!is_array($child)) continue;
            $ct=$child['type']??'';
            $cc=$child['coordinates']??null;
            if ($ct==='LineString' && is_array($cc)) $out[]=$cc;
            elseif ($ct==='MultiLineString' && is_array($cc)) {
                foreach ($cc as $line) if (is_array($line)) $out[]=$line;
            }
        }
        return $out;
    }
    return [];
}

function navBestGeometryLine(array $lines): array {
    $best=[]; $score=-1.0;
    foreach ($lines as $line) {
        if (!is_array($line) || count($line)<2) continue;
        $length=0.0;
        for ($i=0;$i<count($line)-1;$i++) {
            $a=$line[$i]; $b=$line[$i+1];
            if (!is_array($a)||!is_array($b)||count($a)<2||count($b)<2) continue;
            $length+=haversineNm((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
        }
        if ($length>$score) { $score=$length; $best=$line; }
    }
    return $best;
}

function navLoadRoute(PDO $pdo,string $ident,string $type): ?array {
    static $cache=[];
    $ident=strtoupper(trim($ident));
    $type=strtolower(trim($type));
    $key=$type.'|'.$ident;
    if (array_key_exists($key,$cache)) return $cache[$key];

    $stmt=$pdo->prepare(
        "SELECT
            r.id AS route_id,r.ident AS route_ident,r.type AS route_type,
            rm.id AS membership_id,rm.member_index,rm.forward,rm.backward,
            rm.lower_text AS membership_lower_text,
            rm.upper_text AS membership_upper_text,
            rm.upper_unlimited AS membership_upper_unlimited,
            s.id AS segment_id,s.from_ident,s.to_ident,
            s.lower_text AS segment_lower_text,
            s.upper_text AS segment_upper_text,
            s.upper_unlimited AS segment_upper_unlimited,
            rg.id AS geometry_id,rg.geometry_json
         FROM nav_routes r
         JOIN nav_route_memberships rm ON rm.route_id=r.id
         JOIN nav_route_segments s ON s.id=rm.segment_id
         LEFT JOIN nav_route_geometry rg ON rg.segment_id=s.id
         WHERE r.ident=? AND r.type=?
         ORDER BY rm.member_index,rm.id,rg.id"
    );
    $stmt->execute([$ident,$type]);

    $segments=[];
    $routeId=null;
    while ($row=$stmt->fetch()) {
        $routeId=(int)$row['route_id'];
        $segKey=(string)$row['membership_id'].':'.(string)$row['segment_id'];
        if (!isset($segments[$segKey])) {
            $segments[$segKey]=[
                'membershipId'=>(int)$row['membership_id'],
                'memberIndex'=>(int)$row['member_index'],
                'segmentId'=>(int)$row['segment_id'],
                'from'=>strtoupper((string)$row['from_ident']),
                'to'=>strtoupper((string)$row['to_ident']),
                'forward'=>$row['forward']===null?null:(int)$row['forward'],
                'backward'=>$row['backward']===null?null:(int)$row['backward'],
                'lowerText'=>$row['membership_lower_text'] ?: $row['segment_lower_text'],
                'upperText'=>$row['membership_upper_text'] ?: $row['segment_upper_text'],
                'upperUnlimited'=>(int)($row['membership_upper_unlimited'] ?: $row['segment_upper_unlimited']),
                'geometryLines'=>[]
            ];
        }
        foreach (navGeoLines($row['geometry_json']??null) as $line) {
            $segments[$segKey]['geometryLines'][]=$line;
        }
    }

    if ($routeId===null) {
        $cache[$key]=null;
        return null;
    }

    $nodes=[]; $adj=[]; $undirected=[]; $indegree=[]; $outdegree=[];
    foreach ($segments as &$seg) {
        $seg['geometry']=navBestGeometryLine($seg['geometryLines']);
        unset($seg['geometryLines']);

        $from=$seg['from']; $to=$seg['to'];
        if ($from==='') $from='@'.$seg['segmentId'].'A';
        if ($to==='') $to='@'.$seg['segmentId'].'B';
        $seg['from']=$from; $seg['to']=$to;

        if ($seg['geometry']) {
            $first=$seg['geometry'][0];
            $last=$seg['geometry'][count($seg['geometry'])-1];
            if (is_array($first)&&count($first)>=2) $nodes[$from]=['lat'=>(float)$first[1],'lon'=>(float)$first[0]];
            if (is_array($last)&&count($last)>=2) $nodes[$to]=['lat'=>(float)$last[1],'lon'=>(float)$last[0]];
        }

        $f=$seg['forward']; $b=$seg['backward'];
        $allowForward=$f===null&&$b===null ? true : $f===1;
        $allowBackward=$f===null&&$b===null ? true : $b===1;

        if ($allowForward) {
            $adj[$from][]=array_merge($seg,['next'=>$to,'reverse'=>false]);
            $outdegree[$from]=($outdegree[$from]??0)+1;
            $indegree[$to]=($indegree[$to]??0)+1;
        }
        if ($allowBackward) {
            $adj[$to][]=array_merge($seg,['next'=>$from,'reverse'=>true]);
            $outdegree[$to]=($outdegree[$to]??0)+1;
            $indegree[$from]=($indegree[$from]??0)+1;
        }

        $undirected[$from][]=array_merge($seg,['next'=>$to,'reverse'=>false]);
        $undirected[$to][]=array_merge($seg,['next'=>$from,'reverse'=>true]);
        $indegree[$from]=$indegree[$from]??0; $indegree[$to]=$indegree[$to]??0;
        $outdegree[$from]=$outdegree[$from]??0; $outdegree[$to]=$outdegree[$to]??0;
    }
    unset($seg);

    $cache[$key]=[
        'id'=>$routeId,
        'ident'=>$ident,
        'type'=>$type,
        'segments'=>array_values($segments),
        'nodes'=>$nodes,
        'adj'=>$adj,
        'undirected'=>$undirected,
        'indegree'=>$indegree,
        'outdegree'=>$outdegree
    ];
    return $cache[$key];
}

function navRouteHasNode(?array $route,string $ident): bool {
    if (!$route) return false;
    $ident=strtoupper($ident);
    return isset($route['indegree'][$ident]) || isset($route['outdegree'][$ident]) || isset($route['nodes'][$ident]);
}

function navNearestRouteNode(array $route,array $coord,string $mode='any'): ?string {
    $candidates=array_unique(array_merge(
        array_keys($route['indegree']??[]),
        array_keys($route['outdegree']??[]),
        array_keys($route['nodes']??[])
    ));
    if (!$candidates) return null;

    if ($mode==='source') {
        $preferred=array_values(array_filter($candidates,fn($n)=>(($route['indegree'][$n]??0)===0 && ($route['outdegree'][$n]??0)>0)));
        if ($preferred) $candidates=$preferred;
    } elseif ($mode==='sink') {
        $preferred=array_values(array_filter($candidates,fn($n)=>(($route['outdegree'][$n]??0)===0 && ($route['indegree'][$n]??0)>0)));
        if ($preferred) $candidates=$preferred;
    }

    $best=null; $bestD=INF;
    foreach ($candidates as $node) {
        $p=$route['nodes'][$node]??null;
        if (!$p) continue;
        $d=haversineNm((float)$coord['lat'],(float)$coord['lon'],(float)$p['lat'],(float)$p['lon']);
        if ($d<$bestD) { $bestD=$d; $best=$node; }
    }
    return $best;
}

function navGraphPath(array $graph,string $start,string $end): ?array {
    if ($start===$end) return [];
    $queue=[[$start,[]]];
    $seen=[$start=>true];

    while ($queue) {
        [$node,$path]=array_shift($queue);
        foreach ($graph[$node]??[] as $edge) {
            $next=(string)$edge['next'];
            if (isset($seen[$next])) continue;
            $nextPath=$path; $nextPath[]=$edge;
            if ($next===$end) return $nextPath;
            $seen[$next]=true;
            $queue[]=[$next,$nextPath];
        }
    }
    return null;
}

function navFindRoutePath(
    array $route,
    ?string $startIdent,
    ?string $endIdent,
    ?array $startCoord,
    ?array $endCoord,
    bool $preferSource=false,
    bool $preferSink=false
): ?array {
    $start=$startIdent!==null?strtoupper($startIdent):null;
    $end=$endIdent!==null?strtoupper($endIdent):null;

    if (!$start || !navRouteHasNode($route,$start)) {
        if (!$startCoord) return null;
        $start=navNearestRouteNode($route,$startCoord,$preferSource?'source':'any');
    }
    if (!$end || !navRouteHasNode($route,$end)) {
        if (!$endCoord) return null;
        $end=navNearestRouteNode($route,$endCoord,$preferSink?'sink':'any');
    }
    if (!$start || !$end) return null;

    $path=navGraphPath($route['adj'],$start,$end);
    $directionFallback=false;
    if ($path===null) {
        $path=navGraphPath($route['undirected'],$start,$end);
        $directionFallback=$path!==null;
    }
    if ($path===null) return null;

    return [
        'start'=>$start,
        'end'=>$end,
        'edges'=>$path,
        'directionFallback'=>$directionFallback
    ];
}

function navAppendLatLon(array &$route,float $lat,float $lon): void {
    if ($route) {
        $last=$route[count($route)-1];
        if (haversineNm((float)$last[0],(float)$last[1],$lat,$lon)<0.02) return;
    }
    $route[]=[round($lat,6),round($lon,6)];
}

function navAppendDirect(array &$route,array $from,array $to): void {
    $d=haversineNm((float)$from['lat'],(float)$from['lon'],(float)$to['lat'],(float)$to['lon']);
    $count=max(2,min(80,(int)ceil($d/18)+1));
    foreach (greatCircle((float)$from['lat'],(float)$from['lon'],(float)$to['lat'],(float)$to['lon'],$count) as $p) {
        navAppendLatLon($route,(float)$p[0],(float)$p[1]);
    }
}

function navAppendPath(array &$route,array $path,array $routeDef): ?array {
    $lastCoord=null;
    foreach ($path['edges'] as $edge) {
        $line=$edge['geometry']??[];
        if ($line && ($edge['reverse']??false)) $line=array_reverse($line);

        if ($line) {
            foreach ($line as $coord) {
                if (!is_array($coord)||count($coord)<2) continue;
                navAppendLatLon($route,(float)$coord[1],(float)$coord[0]);
                $lastCoord=['lat'=>(float)$coord[1],'lon'=>(float)$coord[0]];
            }
        } else {
            $a=$routeDef['nodes'][$edge['reverse']?$edge['to']:$edge['from']]??null;
            $b=$routeDef['nodes'][$edge['reverse']?$edge['from']:$edge['to']]??null;
            if ($a&&$b) {
                navAppendDirect($route,$a,$b);
                $lastCoord=$b;
            }
        }
    }
    if ($lastCoord===null) $lastCoord=$routeDef['nodes'][$path['end']]??null;
    return $lastCoord;
}

function navPolylineDistance(array $route): float {
    $d=0.0;
    for ($i=0;$i<count($route)-1;$i++) {
        $d+=haversineNm((float)$route[$i][0],(float)$route[$i][1],(float)$route[$i+1][0],(float)$route[$i+1][1]);
    }
    return $d;
}

function navChoosePoint(
    string $ident,
    array $rows,
    array $directRoute,
    float $lastProgress,
    ?array $currentCoord,
    array $contextRoutes=[]
): ?array {
    $ident=strtoupper($ident);
    $best=null; $bestScore=INF;

    foreach ($rows as $row) {
        if (!isset($row['lat'],$row['lon'])) continue;
        $lat=(float)$row['lat']; $lon=(float)$row['lon'];
        [$offRoute,$progress]=nearestRoute($directRoute,$lat,$lon);
        $score=$offRoute;

        if ($progress+0.10<$lastProgress) $score+=650.0;
        if ($currentCoord) {
            $score+=min(400.0,haversineNm(
                (float)$currentCoord['lat'],(float)$currentCoord['lon'],$lat,$lon
            ))*0.08;
        }

        foreach ($contextRoutes as $route) {
            if (!$route || !isset($route['nodes'][$ident])) continue;
            $rp=$route['nodes'][$ident];
            $score+=haversineNm($lat,$lon,(float)$rp['lat'],(float)$rp['lon'])*5.0;
        }

        if ($score<$bestScore) {
            $bestScore=$score;
            $best=[
                'id'=>$ident,
                'type'=>($row['kind']??'designatedpoint')==='designatedpoint'?'fix':($row['kind']??'fix'),
                'lat'=>$lat,'lon'=>$lon,
                'progress'=>$progress,
                'sourceId'=>(int)$row['id'],
                'kind'=>$row['kind']??null
            ];
        }
    }
    return $best;
}

function navRouteTypeForItem(array $item,int $index,int $count): ?string {
    $kind=$item['kind']??'';
    if ($kind==='airway') return 'airway';
    if ($kind!=='procedure') return null;
    $role=strtolower((string)($item['role']??''));
    if ($role==='sid') return 'sid';
    if ($role==='star') return 'star';
    if ($index < max(2,(int)floor($count*0.35))) return 'sid';
    if ($index > min($count-3,(int)ceil($count*0.65))) return 'star';
    return null;
}

function resolveUserRoute(string $raw,string $from,string $to,array $departure,array $arrival): array {
    $parsed=parseRouteTokens($raw,$from,$to);
    $items=$parsed['parsed'];
    $pdo=navDb();

    // If navdata DB is unavailable, retain the previous AWC point-only resolver.
    if (!$pdo) {
        $fixIds=[]; $navaidIds=[];
        foreach ($items as $item) {
            if (($item['kind']??'')==='fix') $fixIds[]=$item['token'];
            if (($item['kind']??'')==='navaid') $navaidIds[]=$item['token'];
        }
        $fixRows=[]; $navaidRows=[];
        if ($fixIds) {
            $res=awcGet('fix',['ids'=>implode(',',array_values(array_unique($fixIds))),'format'=>'json']);
            if ($res['ok']&&is_array($res['data'])) $fixRows=groupNavRows($res['data']);
        }
        if ($navaidIds) {
            $res=awcGet('navaid',['ids'=>implode(',',array_values(array_unique($navaidIds))),'format'=>'json']);
            if ($res['ok']&&is_array($res['data'])) $navaidRows=groupNavRows($res['data']);
        }

        $directDistance=haversineNm((float)$departure['lat'],(float)$departure['lon'],(float)$arrival['lat'],(float)$arrival['lon']);
        $directRoute=greatCircle((float)$departure['lat'],(float)$departure['lon'],(float)$arrival['lat'],(float)$arrival['lon'],max(40,min(140,(int)ceil($directDistance/18)+1)));
        $points=[['id'=>$from,'type'=>'departure','lat'=>(float)$departure['lat'],'lon'=>(float)$departure['lon']]];
        $resolved=$points; $unresolved=[]; $pendingNavdata=[]; $lastProgress=0.0;
        foreach ($items as $item) {
            $id=(string)($item['token']??''); $kind=(string)($item['kind']??'');
            if ($kind==='coordinate') {
                $candidate=['id'=>$id,'type'=>'coordinate','lat'=>(float)$item['coord']['lat'],'lon'=>(float)$item['coord']['lon']];
                $points[]=$candidate; $resolved[]=$candidate; continue;
            }
            if ($kind==='airway'||$kind==='procedure') {
                $pendingNavdata[]=['token'=>$id,'kind'=>$kind,'role'=>$item['role']??null];
                continue;
            }
            if (!in_array($kind,['fix','navaid'],true)) continue;
            $rows=$kind==='fix'?($fixRows[$id]??[]):($navaidRows[$id]??[]);
            $chosen=chooseNavCandidate($rows,$directRoute,$lastProgress,1200.0);
            if (!$chosen) { $unresolved[]=$id; continue; }
            $candidate=['id'=>$id,'type'=>$kind,'lat'=>$chosen['lat'],'lon'=>$chosen['lon']];
            $points[]=$candidate; $resolved[]=$candidate; $lastProgress=max($lastProgress,(float)$chosen['progress']);
        }
        $arrivalPoint=['id'=>$to,'type'=>'arrival','lat'=>(float)$arrival['lat'],'lon'=>(float)$arrival['lon']];
        $points[]=$arrivalPoint; $resolved[]=$arrivalPoint;
        [$fallbackRoute,$fallbackDistance]=buildRoute($points);
        return [
            'usable'=>count($points)>2,'points'=>$points,'route'=>$fallbackRoute,'distanceNm'=>$fallbackDistance,
            'resolved'=>$resolved,'unresolved'=>array_values(array_unique($unresolved)),'pendingNavdata'=>$pendingNavdata,
            'ignored'=>$parsed['ignored'],'unknown'=>$parsed['unknown'],'structure'=>$parsed['structure'],
            'verticalProfile'=>$parsed['verticalProfile'],'alternates'=>$parsed['alternates'],'recognized'=>$items,
            'engine'=>'awc_fallback','navdataResolved'=>[],'warnings'=>['MariaDB navdata unavailable; AWC point fallback used.']
        ];
    }

    $pointIds=[];
    foreach ($items as $item) {
        if (in_array($item['kind']??'',['fix','navaid','unknown'],true)) $pointIds[]=$item['token'];
    }
    $pointCandidates=navFetchPointCandidates($pdo,$pointIds);

    $routeDefs=[];
    foreach ($items as $i=>$item) {
        $type=navRouteTypeForItem($item,$i,count($items));
        if (!$type) continue;
        $ident=strtoupper((string)($item['token']??''));
        $def=navLoadRoute($pdo,$ident,$type);
        if (!$def && ($item['kind']??'')==='procedure') {
            $alt=$type==='sid'?'star':'sid';
            $def=navLoadRoute($pdo,$ident,$alt);
            if ($def) $type=$alt;
        }
        if ($def) $routeDefs[$i]=$def;
    }

    $directDistance=haversineNm((float)$departure['lat'],(float)$departure['lon'],(float)$arrival['lat'],(float)$arrival['lon']);
    $directRoute=greatCircle(
        (float)$departure['lat'],(float)$departure['lon'],
        (float)$arrival['lat'],(float)$arrival['lon'],
        max(40,min(140,(int)ceil($directDistance/18)+1))
    );

    $depPoint=['id'=>$from,'type'=>'departure','lat'=>(float)$departure['lat'],'lon'=>(float)$departure['lon']];
    $arrPoint=['id'=>$to,'type'=>'arrival','lat'=>(float)$arrival['lat'],'lon'=>(float)$arrival['lon']];
    $route=[[$depPoint['lat'],$depPoint['lon']]];
    $points=[$depPoint]; $resolved=[$depPoint]; $unresolved=[]; $pending=[]; $navResolved=[]; $warnings=[];
    $currentIdent=$from; $currentCoord=['lat'=>$depPoint['lat'],'lon'=>$depPoint['lon']]; $lastProgress=0.0;

    $nextPointInfo=function(int $fromIndex) use (&$items,$pointCandidates,$directRoute,&$lastProgress,&$currentCoord,&$routeDefs): ?array {
        for ($j=$fromIndex+1;$j<count($items);$j++) {
            $next=$items[$j]; $kind=$next['kind']??'';
            if ($kind==='coordinate') {
                return [
                    'ident'=>null,
                    'coord'=>['lat'=>(float)$next['coord']['lat'],'lon'=>(float)$next['coord']['lon']],
                    'itemIndex'=>$j
                ];
            }
            if (in_array($kind,['fix','navaid','unknown'],true)) {
                $id=strtoupper((string)$next['token']);
                $contexts=[];
                if (isset($routeDefs[$fromIndex])) $contexts[]=$routeDefs[$fromIndex];
                if (isset($routeDefs[$j-1])) $contexts[]=$routeDefs[$j-1];
                $chosen=navChoosePoint($id,$pointCandidates[$id]??[],$directRoute,$lastProgress,$currentCoord,$contexts);
                return ['ident'=>$id,'coord'=>$chosen,'itemIndex'=>$j];
            }
        }
        return null;
    };

    foreach ($items as $i=>$item) {
        $kind=(string)($item['kind']??'');
        $id=strtoupper((string)($item['token']??''));

        if (in_array($kind,['airport','airport_runway','flight_level','dct'],true)) continue;

        if ($kind==='airway'||$kind==='procedure') {
            $def=$routeDefs[$i]??null;
            if (!$def) {
                $pending[]=['token'=>$id,'kind'=>$kind,'role'=>$item['role']??null,'reason'=>'route_not_found'];
                continue;
            }

            $type=$def['type'];
            $next=$nextPointInfo($i);
            $startIdent=$currentIdent;
            $endIdent=$next['ident']??null;
            $endCoord=$next['coord']??null;
            $preferSource=$type==='sid';
            $preferSink=$type==='star';

            if ($type==='star') {
                $endIdent=$to;
                $endCoord=['lat'=>$arrPoint['lat'],'lon'=>$arrPoint['lon']];
            } elseif ($type==='sid' && !$next) {
                $endIdent=null;
                $endCoord=['lat'=>$arrPoint['lat'],'lon'=>$arrPoint['lon']];
            }

            $path=navFindRoutePath(
                $def,
                $startIdent,
                $endIdent,
                $currentCoord,
                $endCoord,
                $preferSource,
                $preferSink
            );

            if (!$path) {
                $pending[]=['token'=>$id,'kind'=>$kind,'role'=>$item['role']??null,'reason'=>'path_not_resolved'];
                continue;
            }

            $last=navAppendPath($route,$path,$def);
            $currentIdent=$path['end'];
            if ($last) $currentCoord=$last;
            elseif (isset($def['nodes'][$currentIdent])) $currentCoord=$def['nodes'][$currentIdent];

            $navResolved[]=[
                'id'=>$id,
                'type'=>$type,
                'routeId'=>$def['id'],
                'start'=>$path['start'],
                'end'=>$path['end'],
                'segments'=>count($path['edges']),
                'directionFallback'=>$path['directionFallback']
            ];
            if ($path['directionFallback']) $warnings[]="$type $id yön bilgisiyle doğrudan çözülemedi; topoloji üzerinden ters-yön fallback kullanıldı.";
            continue;
        }

        if ($kind==='coordinate') {
            $candidate=['id'=>$id,'type'=>'coordinate','lat'=>(float)$item['coord']['lat'],'lon'=>(float)$item['coord']['lon']];
            if (isset($item['levelFL'])) $candidate['levelFL']=$item['levelFL'];
        } elseif (in_array($kind,['fix','navaid','unknown'],true)) {
            $contexts=[];
            if (isset($routeDefs[$i-1])) $contexts[]=$routeDefs[$i-1];
            if (isset($routeDefs[$i+1])) $contexts[]=$routeDefs[$i+1];
            $candidate=navChoosePoint($id,$pointCandidates[$id]??[],$directRoute,$lastProgress,$currentCoord,$contexts);
            if (!$candidate) {
                $unresolved[]=$id;
                continue;
            }
            if (isset($item['levelFL'])) $candidate['levelFL']=$item['levelFL'];
        } else {
            continue;
        }

        if ($currentIdent===$candidate['id'] || haversineNm(
            (float)$currentCoord['lat'],(float)$currentCoord['lon'],
            (float)$candidate['lat'],(float)$candidate['lon']
        )<0.3) {
            $resolved[]=$candidate;
            $points[]=$candidate;
            $currentIdent=$candidate['id'];
            $currentCoord=['lat'=>$candidate['lat'],'lon'=>$candidate['lon']];
            $lastProgress=max($lastProgress,(float)($candidate['progress']??$lastProgress));
            continue;
        }

        navAppendDirect($route,$currentCoord,$candidate);
        $currentIdent=$candidate['id'];
        $currentCoord=['lat'=>$candidate['lat'],'lon'=>$candidate['lon']];
        $lastProgress=max($lastProgress,(float)($candidate['progress']??$lastProgress));
        $resolved[]=$candidate; $points[]=$candidate;
    }

    if (haversineNm(
        (float)$currentCoord['lat'],(float)$currentCoord['lon'],
        (float)$arrPoint['lat'],(float)$arrPoint['lon']
    )>0.3) {
        navAppendDirect($route,$currentCoord,['lat'=>$arrPoint['lat'],'lon'=>$arrPoint['lon']]);
    }
    $points[]=$arrPoint; $resolved[]=$arrPoint;

    return [
        'usable'=>count($route)>=2,
        'points'=>$points,
        'route'=>$route,
        'distanceNm'=>navPolylineDistance($route),
        'resolved'=>$resolved,
        'unresolved'=>array_values(array_unique($unresolved)),
        'pendingNavdata'=>$pending,
        'ignored'=>$parsed['ignored'],
        'unknown'=>$parsed['unknown'],
        'structure'=>$parsed['structure'],
        'verticalProfile'=>$parsed['verticalProfile'],
        'alternates'=>$parsed['alternates'],
        'recognized'=>$items,
        'engine'=>'mariadb_navdata',
        'navdataResolved'=>$navResolved,
        'warnings'=>array_values(array_unique($warnings))
    ];
}

function normalizeStation(array $s, array $route): ?array {
    $icao = strtoupper((string)($s['icaoId'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) return null;
    if (!isset($s['lat'], $s['lon']) || !is_numeric($s['lat']) || !is_numeric($s['lon'])) return null;
    [$routeDistance, $progress] = nearestRoute($route, (float)$s['lat'], (float)$s['lon']);
    $types = array_map('strtoupper', is_array($s['siteType'] ?? null) ? $s['siteType'] : []);
    return [
        'icao'=>$icao,
        'name'=>(string)($s['site'] ?? $s['name'] ?? $icao),
        'lat'=>(float)$s['lat'],
        'lon'=>(float)$s['lon'],
        'country'=>(string)($s['country'] ?? ''),
        'state'=>(string)($s['state'] ?? ''),
        'priority'=>is_numeric($s['priority'] ?? null) ? (int)$s['priority'] : 99,
        'hasMetar'=>in_array('METAR',$types,true),
        'hasTaf'=>in_array('TAF',$types,true),
        'routeDistanceNm'=>round($routeDistance,1),
        'progress'=>round($progress,4),
    ];
}

function selectStations(array $candidates, float $distanceNm, int $maxSlots=8, float $spacingNm=120.0): array {
    // Keep enough en-route reference airports to resemble an operational
    // route-weather strip without turning the page into an airport directory.
    $slots = max(2, min($maxSlots, (int)ceil($distanceNm / max(40.0,$spacingNm))));
    $selected = [];

    for ($b=0; $b<$slots; $b++) {
        $center = ($b + 1) / ($slots + 1);
        $best = null; $bestScore = INF;
        foreach ($candidates as $c) {
            if ($c['progress'] < 0.05 || $c['progress'] > 0.95) continue;
            if (!$c['hasMetar'] && !$c['hasTaf']) continue;
            if (isset($selected[$c['icao']])) continue;
            $score = abs($c['progress'] - $center) * 430
                + $c['routeDistanceNm'] * 1.35
                + ($c['hasMetar'] ? 0 : 35)
                + ($c['hasTaf'] ? 0 : 30)
                + min(60,max(0,$c['priority'])) * 0.7;
            if ($score < $bestScore) { $bestScore=$score; $best=$c; }
        }
        if ($best) $selected[$best['icao']]=$best;
    }

    $out = array_values($selected);
    usort($out, fn($a,$b)=>$a['progress']<=>$b['progress']);
    return $out;
}

function mapByIcao(array $rows): array {
    $out=[];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $icao=strtoupper((string)($row['icaoId'] ?? ''));
        if ($icao!=='') $out[$icao]=$row;
    }
    return $out;
}

function orient(float $ax,float $ay,float $bx,float $by,float $cx,float $cy): float {
    return ($bx-$ax)*($cy-$ay)-($by-$ay)*($cx-$ax);
}

function segmentsIntersect(float $aLon,float $aLat,float $bLon,float $bLat,float $cLon,float $cLat,float $dLon,float $dLat): bool {
    $o1=orient($aLon,$aLat,$bLon,$bLat,$cLon,$cLat);
    $o2=orient($aLon,$aLat,$bLon,$bLat,$dLon,$dLat);
    $o3=orient($cLon,$cLat,$dLon,$dLat,$aLon,$aLat);
    $o4=orient($cLon,$cLat,$dLon,$dLat,$bLon,$bLat);
    return (($o1>0 && $o2<0)||($o1<0 && $o2>0)) && (($o3>0 && $o4<0)||($o3<0 && $o4>0));
}

function pointInRing(float $lon,float $lat,array $ring): bool {
    $inside=false; $n=count($ring);
    if ($n<3) return false;
    for ($i=0,$j=$n-1;$i<$n;$j=$i++) {
        $xi=(float)($ring[$i][0]??0); $yi=(float)($ring[$i][1]??0);
        $xj=(float)($ring[$j][0]??0); $yj=(float)($ring[$j][1]??0);
        $hit=(($yi>$lat)!==($yj>$lat)) && ($lon < ($xj-$xi)*($lat-$yi)/(($yj-$yi) ?: 1e-12)+$xi);
        if ($hit) $inside=!$inside;
    }
    return $inside;
}

function pointSegmentNm(float $lat,float $lon,float $lat1,float $lon1,float $lat2,float $lon2): float {
    $lat0=rad(($lat+$lat1+$lat2)/3);
    $x=($lon-$lon1)*cos($lat0)*60.0;
    $y=($lat-$lat1)*60.0;
    $dx=($lon2-$lon1)*cos($lat0)*60.0;
    $dy=($lat2-$lat1)*60.0;
    $den=$dx*$dx+$dy*$dy;
    if ($den<1e-12) return sqrt($x*$x+$y*$y);
    $t=max(0.0,min(1.0,($x*$dx+$y*$dy)/$den));
    $px=$t*$dx; $py=$t*$dy;
    return sqrt(($x-$px)**2+($y-$py)**2);
}

function polygonDistanceNm(array $route,array $ring): float {
    if (count($ring)<3 || count($route)<2) return INF;

    foreach ($route as $p) {
        if (pointInRing((float)$p[1],(float)$p[0],$ring)) return 0.0;
    }

    for ($i=0;$i<count($route)-1;$i++) {
        $a=$route[$i]; $b=$route[$i+1];
        for ($j=0;$j<count($ring)-1;$j++) {
            $c=$ring[$j]; $d=$ring[$j+1];
            if (segmentsIntersect((float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0],(float)$c[0],(float)$c[1],(float)$d[0],(float)$d[1])) return 0.0;
        }
    }

    $best=INF;
    foreach ($route as $p) {
        for ($j=0;$j<count($ring)-1;$j++) {
            $a=$ring[$j]; $b=$ring[$j+1];
            $d=pointSegmentNm((float)$p[0],(float)$p[1],(float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
            if ($d<$best) $best=$d;
        }
    }
    return $best;
}

function geometryDistanceNm(array $route, ?array $geometry): float {
    if (!$geometry) return INF;
    $type=$geometry['type'] ?? '';
    $coords=$geometry['coordinates'] ?? null;
    if (!is_array($coords)) return INF;

    $best=INF;
    if ($type==='Polygon') {
        $ring=$coords[0] ?? [];
        return is_array($ring) ? polygonDistanceNm($route,$ring) : INF;
    }
    if ($type==='MultiPolygon') {
        foreach ($coords as $poly) {
            $ring=$poly[0] ?? [];
            if (!is_array($ring)) continue;
            $d=polygonDistanceNm($route,$ring);
            if ($d<$best) $best=$d;
        }
        return $best;
    }
    if ($type==='LineString') {
        foreach ($route as $p) {
            for ($j=0;$j<count($coords)-1;$j++) {
                $a=$coords[$j]; $b=$coords[$j+1];
                $d=pointSegmentNm((float)$p[0],(float)$p[1],(float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
                if ($d<$best) $best=$d;
            }
        }
        return $best;
    }
    if ($type==='Point' && count($coords)>=2) {
        [$d] = nearestRoute($route,(float)$coords[1],(float)$coords[0]);
        return $d;
    }
    return INF;
}

function rawSigmet(array $feature): string {
    $p=$feature['properties'] ?? [];
    foreach (['rawSigmet','rawText','rawSIGMET','text','raw'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) return trim($p[$k]);
    }
    return '';
}

function hazardType(array $feature,string $raw): string {
    $p=$feature['properties'] ?? [];
    foreach (['hazard','hazardType','phenomenon'] as $k) {
        if (!empty($p[$k])) return strtoupper((string)$p[$k]);
    }
    $u=' '.strtoupper($raw).' ';
    if (str_contains($u,' TS ') || str_contains($u,' EMBD TS') || str_contains($u,' FRQ TS')) return 'TS';
    if (str_contains($u,' TURB')) return 'TURB';
    if (str_contains($u,' ICE')) return 'ICE';
    if (str_contains($u,' VA ') || str_contains($u,' VOLCAN')) return 'VA';
    if (str_contains($u,' TC ')) return 'TC';
    if (str_contains($u,' MTW')) return 'MTW';
    if (str_contains($u,' DS ') || str_contains($u,' SS ')) return 'DS/SS';
    return 'SIGMET';
}

function parseVertical(string $raw): array {
    $u=strtoupper($raw);
    $lo=null; $hi=null; $source='unknown';

    if (preg_match('/TOP\s+ABV\s+FL(\d{2,3})/', $u,$m)) {
        return ['bottomFL'=>null,'topFL'=>null,'topAboveFL'=>(int)$m[1],'source'=>'top-above'];
    }
    if (preg_match('/BTN\s+FL(\d{2,3})\s+(?:AND|\/)\s+(?:FL)?(\d{2,3})/', $u,$m)) {
        $lo=min((int)$m[1],(int)$m[2]); $hi=max((int)$m[1],(int)$m[2]); $source='range';
    } elseif (preg_match('/FL(\d{2,3})\s*\/\s*(?:FL)?(\d{2,3})/', $u,$m)) {
        $lo=min((int)$m[1],(int)$m[2]); $hi=max((int)$m[1],(int)$m[2]); $source='range';
    } elseif (preg_match('/SFC\s*\/\s*FL(\d{2,3})/', $u,$m)) {
        $lo=0; $hi=(int)$m[1]; $source='sfc-top';
    } elseif (preg_match('/BLW\s+FL(\d{2,3})/', $u,$m)) {
        $lo=0; $hi=(int)$m[1]; $source='below';
    } elseif (preg_match('/ABV\s+FL(\d{2,3})/', $u,$m)) {
        $lo=(int)$m[1]; $hi=null; $source='above';
    } elseif (preg_match('/TOP\s+(?:ABV\s+)?FL(\d{2,3})/', $u,$m)) {
        $hi=(int)$m[1]; $source='top';
    }

    return ['bottomFL'=>$lo,'topFL'=>$hi,'source'=>$source];
}

function cruiseRelation(array $vertical,int $cruiseFL): string {
    $lo=$vertical['bottomFL']; $hi=$vertical['topFL'];
    if ($lo===null && $hi===null) return 'unknown';
    if ($lo!==null && $cruiseFL<$lo) return 'below_hazard_layer';
    if ($hi!==null && $cruiseFL>$hi) return 'above_hazard_layer';
    if ($lo===null || ($hi===null && $vertical['source']!=='above')) return 'unknown';
    return 'at_cruise_level';
}

function firstTimestamp(array $props,array $keys): ?int {
    foreach ($keys as $k) {
        if (!isset($props[$k]) || $props[$k]==='') continue;
        $v=$props[$k];
        if (is_numeric($v)) {
            $n=(int)$v;
            if ($n>1000000000000) $n=(int)round($n/1000);
            if ($n>1000000000) return $n;
        }
        $t=strtotime((string)$v);
        if ($t!==false) return $t;
    }
    return null;
}

function pointGeometryDistanceNm(float $lat,float $lon,?array $geometry): float {
    if (!$geometry) return INF;
    $type=$geometry['type'] ?? '';
    $coords=$geometry['coordinates'] ?? null;
    if (!is_array($coords)) return INF;

    $ringDistance=function(array $ring) use ($lat,$lon): float {
        if (count($ring)<2) return INF;
        if (count($ring)>=3 && pointInRing($lon,$lat,$ring)) return 0.0;
        $best=INF;
        for ($j=0;$j<count($ring)-1;$j++) {
            $a=$ring[$j]; $b=$ring[$j+1];
            if (!is_array($a)||!is_array($b)||count($a)<2||count($b)<2) continue;
            $d=pointSegmentNm($lat,$lon,(float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
            if ($d<$best) $best=$d;
        }
        return $best;
    };

    if ($type==='Polygon') return $ringDistance(is_array($coords[0]??null)?$coords[0]:[]);
    if ($type==='MultiPolygon') {
        $best=INF;
        foreach ($coords as $poly) {
            $ring=is_array($poly[0]??null)?$poly[0]:[];
            $d=$ringDistance($ring);
            if ($d<$best) $best=$d;
        }
        return $best;
    }
    if ($type==='LineString') {
        $best=INF;
        for ($j=0;$j<count($coords)-1;$j++) {
            $a=$coords[$j]; $b=$coords[$j+1];
            if (!is_array($a)||!is_array($b)||count($a)<2||count($b)<2) continue;
            $d=pointSegmentNm($lat,$lon,(float)$a[1],(float)$a[0],(float)$b[1],(float)$b[0]);
            if ($d<$best) $best=$d;
        }
        return $best;
    }
    if ($type==='Point' && count($coords)>=2) return haversineNm($lat,$lon,(float)$coords[1],(float)$coords[0]);
    return INF;
}

function routeEncounterWindow(array $route,?array $geometry,int $etdEpoch,int $flightEndEpoch,float $thresholdNm=100.0): ?array {
    $n=count($route);
    if ($n<2 || !$geometry) return null;
    $first=null; $last=null; $closestIdx=null; $closest=INF;

    foreach ($route as $i=>$p) {
        $d=pointGeometryDistanceNm((float)$p[0],(float)$p[1],$geometry);
        if ($d<$closest) { $closest=$d; $closestIdx=$i; }
        if ($d<=$thresholdNm) {
            if ($first===null) $first=$i;
            $last=$i;
        }
    }
    if ($first===null || $last===null) return null;

    $span=max(1,$n-1);
    $duration=max(0,$flightEndEpoch-$etdEpoch);
    $progressStart=$first/$span;
    $progressEnd=$last/$span;
    $progressClosest=($closestIdx??$first)/$span;

    return [
        'progressStart'=>round($progressStart,4),
        'progressEnd'=>round($progressEnd,4),
        'progressClosest'=>round($progressClosest,4),
        'etaStart'=>$etdEpoch+(int)round($duration*$progressStart),
        'etaEnd'=>$etdEpoch+(int)round($duration*$progressEnd),
        'etaClosest'=>$etdEpoch+(int)round($duration*$progressClosest),
        'closestDistanceNm'=>round($closest,1),
        'thresholdNm'=>$thresholdNm
    ];
}

function analyzeSigmets(array $sigmets,array $route,int $cruiseFL,int $etdEpoch,int $flightEndEpoch): array {
    $features=is_array($sigmets['features'] ?? null) ? $sigmets['features'] : [];
    $out=[];

    foreach ($features as $feature) {
        if (!is_array($feature)) continue;
        $geometry=is_array($feature['geometry'] ?? null)?$feature['geometry']:null;
        $d=geometryDistanceNm($route,$geometry);
        if (!is_finite($d) || $d>100.0) continue;

        $raw=rawSigmet($feature);
        $vertical=parseVertical($raw);
        $props=is_array($feature['properties'] ?? null)?$feature['properties']:[];
        $validFrom=firstTimestamp($props,['validTimeFrom','validFrom','startTime','validStart','issueTime']);
        $validTo=firstTimestamp($props,['validTimeTo','validTo','endTime','validEnd','expireTime']);
        $encounter=routeEncounterWindow($route,$geometry,$etdEpoch,$flightEndEpoch,100.0);
        $timeRelation='unknown';

        if ($validFrom!==null && $validTo!==null) {
            $flightOverlap=($validFrom <= $flightEndEpoch && $validTo >= $etdEpoch);
            if (!$flightOverlap) {
                $timeRelation='outside_flight_window';
            } elseif ($encounter) {
                $routeOverlap=($validFrom <= $encounter['etaEnd'] && $validTo >= $encounter['etaStart']);
                $timeRelation=$routeOverlap?'overlaps_route_eta':'outside_route_eta';
            } else {
                // Geometry passed the 100 NM test but encounter sampling could not
                // resolve a local ETA; keep it visible rather than claiming it is irrelevant.
                $timeRelation='unknown';
            }
        }

        $proximity=$d<0.5?'INTERSECTS':($d<=50?'NEAR_ROUTE':'WITHIN_100NM');
        $out[]=[
            'hazard'=>hazardType($feature,$raw),
            'distanceNm'=>round($d,1),
            'proximity'=>$proximity,
            'vertical'=>$vertical,
            'cruiseRelation'=>cruiseRelation($vertical,$cruiseFL),
            'timeRelation'=>$timeRelation,
            'validFrom'=>$validFrom ? gmdate('c',$validFrom) : null,
            'validTo'=>$validTo ? gmdate('c',$validTo) : null,
            'routeEncounter'=>$encounter?[
                'progressStart'=>$encounter['progressStart'],
                'progressEnd'=>$encounter['progressEnd'],
                'progressClosest'=>$encounter['progressClosest'],
                'etaStart'=>gmdate('c',$encounter['etaStart']),
                'etaEnd'=>gmdate('c',$encounter['etaEnd']),
                'etaClosest'=>gmdate('c',$encounter['etaClosest']),
                'closestDistanceNm'=>$encounter['closestDistanceNm'],
                'thresholdNm'=>$encounter['thresholdNm']
            ]:null,
            'raw'=>$raw,
            'feature'=>$feature
        ];
    }

    usort($out,function($a,$b){
        $time=['overlaps_route_eta'=>0,'unknown'=>1,'outside_route_eta'=>2,'outside_flight_window'=>3];
        $ta=$time[$a['timeRelation']]??9; $tb=$time[$b['timeRelation']]??9;
        if ($ta!==$tb) return $ta<=>$tb;
        $p=['INTERSECTS'=>0,'NEAR_ROUTE'=>1,'WITHIN_100NM'=>2];
        $c=($p[$a['proximity']]??9)<=>($p[$b['proximity']]??9);
        return $c!==0?$c:($a['distanceNm']<=>$b['distanceNm']);
    });
    return $out;
}

$from=strtoupper(trim((string)($_GET['from'] ?? '')));
$to=strtoupper(trim((string)($_GET['to'] ?? '')));
$routeRaw=strtoupper(trim((string)($_GET['route'] ?? '')));
if (strlen($routeRaw)>2000) $routeRaw=substr($routeRaw,0,2000);
$cruiseFL=(int)($_GET['fl'] ?? 360);
$cruiseFL=max(50,min(600,$cruiseFL));

$etdRaw=trim((string)($_GET['etd'] ?? ''));
$etdEpoch=$etdRaw!=='' ? strtotime($etdRaw.' UTC') : time();
if ($etdEpoch===false) $etdEpoch=time();

if (!preg_match('/^[A-Z0-9]{4}$/',$from) || !preg_match('/^[A-Z0-9]{4}$/',$to) || $from===$to) {
    respond(400,['ok'=>false,'error'=>'Geçerli ve farklı iki ICAO kodu girin. Örnek: LTAI → EDDB.']);
}
if (!function_exists('curl_init')) respond(500,['ok'=>false,'error'=>'PHP cURL aktif değil.']);

$cacheKey="{$from}|{$to}|{$cruiseFL}|".gmdate('YmdHi',$etdEpoch).'|'.sha1($routeRaw);
if ($cached=cacheGet($cacheKey,180)) respond(200,$cached);

$airportRes=awcGet('airport',['ids'=>$from.','.$to,'format'=>'json']);
if (!$airportRes['ok'] || !is_array($airportRes['data'])) {
    respond(502,['ok'=>false,'error'=>'Havalimanı bilgileri AviationWeather.gov üzerinden alınamadı.','detail'=>$airportRes['error'] ?? null]);
}
$airports=mapByIcao($airportRes['data']);
if (!isset($airports[$from],$airports[$to])) {
    respond(404,['ok'=>false,'error'=>'ICAO kodlarından biri AviationWeather.gov verisinde bulunamadı.']);
}

$a=$airports[$from]; $b=$airports[$to];
$lat1=(float)$a['lat']; $lon1=(float)$a['lon']; $lat2=(float)$b['lat']; $lon2=(float)$b['lon'];

$routeMode='great_circle';
$resolvedRoute=[];
$routeInput=['raw'=>$routeRaw!==''?$routeRaw:null,'parserVersion'=>'3.0','resolved'=>[],'unresolved'=>[],'pendingNavdata'=>[],'ignored'=>[],'unknown'=>[],'structure'=>null,'verticalProfile'=>null,'alternates'=>[],'recognized'=>[],'engine'=>null,'navdataResolved'=>[],'warnings'=>[]];

if ($routeRaw!=='') {
    $resolvedRoute=resolveUserRoute($routeRaw,$from,$to,$a,$b);
    $routeInput['resolved']=$resolvedRoute['resolved'];
    $routeInput['unresolved']=$resolvedRoute['unresolved'];
    $routeInput['pendingNavdata']=$resolvedRoute['pendingNavdata'];
    $routeInput['ignored']=$resolvedRoute['ignored'];
    $routeInput['unknown']=$resolvedRoute['unknown'];
    $routeInput['structure']=$resolvedRoute['structure'];
    $routeInput['verticalProfile']=$resolvedRoute['verticalProfile'];
    $routeInput['alternates']=$resolvedRoute['alternates'];
    $routeInput['recognized']=$resolvedRoute['recognized'];
    $routeInput['engine']=$resolvedRoute['engine']??null;
    $routeInput['navdataResolved']=$resolvedRoute['navdataResolved']??[];
    $routeInput['warnings']=$resolvedRoute['warnings']??[];
    if ($resolvedRoute['usable']) {
        if (!empty($resolvedRoute['route']) && is_array($resolvedRoute['route'])) {
            $route=$resolvedRoute['route'];
            $distanceNm=(float)($resolvedRoute['distanceNm']??navPolylineDistance($route));
        } else {
            [$route,$distanceNm]=buildRoute($resolvedRoute['points']);
        }
        $routeMode='user_route';
    }
}

if ($routeMode==='great_circle') {
    $distanceNm=haversineNm($lat1,$lon1,$lat2,$lon2);
    $route=greatCircle($lat1,$lon1,$lat2,$lon2,max(40,min(140,(int)ceil($distanceNm/18)+1)));
    $routeInput['resolved']=[
        ['id'=>$from,'type'=>'departure','lat'=>$lat1,'lon'=>$lon1],
        ['id'=>$to,'type'=>'arrival','lat'=>$lat2,'lon'=>$lon2]
    ];
}

$estimatedEetMinutes=max(30,(int)round(($distanceNm/450.0)*60+20));
$flightEndEpoch=$etdEpoch+$estimatedEetMinutes*60;

$probeCount=max(6,min(16,(int)ceil($distanceNm/120)+1));
$probeRoute=sampleRoute($route,$probeCount);
$stationPool=[]; $searchNm=105.0;

foreach ($probeRoute as $p) {
    $lat=(float)$p[0]; $lon=(float)$p[1];
    $latDelta=$searchNm/60.0;
    $cos=max(0.18,abs(cos(rad($lat))));
    $lonDelta=$searchNm/(60.0*$cos);
    $bbox=sprintf('%.3f,%.3f,%.3f,%.3f',max(-90,$lat-$latDelta),max(-180,$lon-$lonDelta),min(90,$lat+$latDelta),min(180,$lon+$lonDelta));
    $res=awcGet('stationinfo',['bbox'=>$bbox,'format'=>'json']);
    if (!$res['ok'] || !is_array($res['data'])) continue;
    foreach ($res['data'] as $row) {
        if (!is_array($row)) continue;
        $n=normalizeStation($row,$route);
        if (!$n || $n['routeDistanceNm']>75.0) continue;
        $stationPool[$n['icao']]=$n;
    }
}

$intermediate=selectStations(array_values($stationPool),$distanceNm,12,75.0);
$endpointStationsRes=awcGet('stationinfo',['ids'=>$from.','.$to,'format'=>'json']);
$endpointMap=$endpointStationsRes['ok'] && is_array($endpointStationsRes['data']) ? mapByIcao($endpointStationsRes['data']) : [];
$fromStation=isset($endpointMap[$from])?normalizeStation($endpointMap[$from],$route):null;
$toStation=isset($endpointMap[$to])?normalizeStation($endpointMap[$to],$route):null;

$fromBase=[
    'icao'=>$from,'name'=>(string)($a['name']??$from),'lat'=>$lat1,'lon'=>$lon1,
    'country'=>(string)($a['country']??''),'state'=>(string)($a['state']??''),
    'hasMetar'=>$fromStation['hasMetar']??true,'hasTaf'=>$fromStation['hasTaf']??true,
    'routeDistanceNm'=>0.0,'progress'=>0.0,'role'=>'departure'
];
$toBase=[
    'icao'=>$to,'name'=>(string)($b['name']??$to),'lat'=>$lat2,'lon'=>$lon2,
    'country'=>(string)($b['country']??''),'state'=>(string)($b['state']??''),
    'hasMetar'=>$toStation['hasMetar']??true,'hasTaf'=>$toStation['hasTaf']??true,
    'routeDistanceNm'=>0.0,'progress'=>1.0,'role'=>'arrival'
];
foreach ($intermediate as &$s) $s['role']='enroute'; unset($s);
$stations=array_merge([$fromBase],$intermediate,[$toBase]);

$ids=implode(',',array_values(array_unique(array_column($stations,'icao'))));
$metarRes=awcGet('metar',['ids'=>$ids,'format'=>'json']);
$tafRes=awcGet('taf',['ids'=>$ids,'format'=>'json']);
$metarMap=$metarRes['ok'] && is_array($metarRes['data']) ? mapByIcao($metarRes['data']) : [];
$tafMap=$tafRes['ok'] && is_array($tafRes['data']) ? mapByIcao($tafRes['data']) : [];

foreach ($stations as &$s) {
    $icao=$s['icao']; $m=$metarMap[$icao]??null; $t=$tafMap[$icao]??null;

    $metarEpoch=is_array($m)?firstTimestamp($m,['obsTime']):null;
    $metarFresh=$metarEpoch!==null && $metarEpoch >= time()-4*3600 && $metarEpoch <= time()+3600;
    $s['metar']=$metarFresh?[
        'raw'=>$m['rawOb']??null,
        'obsTime'=>$m['obsTime']??null,
        'flightCategory'=>$m['fltCat']??null,
        'windDirection'=>$m['wdir']??null,
        'windSpeedKt'=>$m['wspd']??null,
        'windGustKt'=>$m['wgst']??null,
        'visibilitySm'=>$m['visib']??null,
        'weather'=>$m['wxString']??null,
    ]:null;
    $s['metarStale']=is_array($m) && !$metarFresh;

    $tafFrom=is_array($t)?firstTimestamp($t,['validTimeFrom']):null;
    $tafTo=is_array($t)?firstTimestamp($t,['validTimeTo']):null;
    $tafFresh=$tafFrom!==null && $tafTo!==null && $tafFrom <= $flightEndEpoch && $tafTo >= $etdEpoch;
    $s['taf']=$tafFresh?[
        'raw'=>$t['rawTAF']??null,
        'issueTime'=>$t['issueTime']??null,
        'validFrom'=>$t['validTimeFrom']??null,
        'validTo'=>$t['validTimeTo']??null,
    ]:null;
    $s['tafStale']=is_array($t) && !$tafFresh;
    $s['hasMetar']=$s['metar']!==null;
    $s['hasTaf']=$s['taf']!==null;
}
unset($s);

// Re-select up to eight en-route stations after freshness checks, so dead/stale
// stations do not consume the route-weather slots. Endpoints are always retained.
$depStation=null; $arrStation=null; $freshIntermediate=[];
foreach ($stations as $s) {
    if (($s['role']??'')==='departure') { $depStation=$s; continue; }
    if (($s['role']??'')==='arrival') { $arrStation=$s; continue; }
    if ($s['metar']!==null || $s['taf']!==null) $freshIntermediate[]=$s;
}
$intermediate=selectStations($freshIntermediate,$distanceNm,8,120.0);
$stations=array_values(array_filter(array_merge(
    $depStation?[$depStation]:[],
    $intermediate,
    $arrStation?[$arrStation]:[]
)));

$sigmetRes=awcGet('isigmet',['format'=>'geojson'],15);
$sigmetAvailable=$sigmetRes['ok'] && (
    ($sigmetRes['status']??0)===204 ||
    (is_array($sigmetRes['data']??null) && ($sigmetRes['data']['type']??'')==='FeatureCollection' && is_array($sigmetRes['data']['features']??null))
);
$sigmets=$sigmetAvailable && ($sigmetRes['status']??0)!==204 ? $sigmetRes['data'] : ['type'=>'FeatureCollection','features'=>[]];
$hazards=analyzeSigmets($sigmets,$route,$cruiseFL,$etdEpoch,$flightEndEpoch);

$timeRelevant=array_values(array_filter($hazards,fn($h)=>in_array($h['timeRelation'],['overlaps_route_eta','unknown'],true)));
$intersectCount=count(array_filter($timeRelevant,fn($h)=>$h['proximity']==='INTERSECTS'));
$nearCount=count(array_filter($timeRelevant,fn($h)=>$h['proximity']==='NEAR_ROUTE'));
$cruiseCount=count(array_filter($timeRelevant,fn($h)=>$h['cruiseRelation']==='at_cruise_level'));

$payload=[
    'ok'=>true,
    'source'=>'NOAA/NWS Aviation Weather Center',
    'fetchedAt'=>gmdate('c'),
    'routeMode'=>$routeMode,
    'operationalRoute'=>false,
    'distanceNm'=>round($distanceNm,0),
    'from'=>$fromBase,
    'to'=>$toBase,
    'route'=>$route,
    'routeInput'=>$routeInput,
    'routeEngine'=>[
        'name'=>$resolvedRoute['engine']??($routeRaw!==''?'legacy':'great_circle'),
        'navdataResolved'=>$resolvedRoute['navdataResolved']??[],
        'warnings'=>$resolvedRoute['warnings']??[]
    ],
    'stations'=>$stations,
    'flight'=>[
        'etdUtc'=>gmdate('c',$etdEpoch),
        'cruiseFL'=>$cruiseFL,
        'estimatedEetMinutes'=>$estimatedEetMinutes,
        'estimatedArrivalUtc'=>gmdate('c',$flightEndEpoch),
        'eetIsEstimate'=>true
    ],
    'sourceStatus'=>[
        'metar'=>['ok'=>$metarRes['ok'],'httpStatus'=>$metarRes['status'],'error'=>$metarRes['error']??null],
        'taf'=>['ok'=>$tafRes['ok'],'httpStatus'=>$tafRes['status'],'error'=>$tafRes['error']??null],
        'sigmet'=>['ok'=>$sigmetAvailable,'httpStatus'=>$sigmetRes['status'],'error'=>$sigmetAvailable?null:($sigmetRes['error']??'Beklenmeyen SIGMET yanıtı.')]
    ],
    'hazards'=>$hazards,
    'hazardSummary'=>[
        'intersects'=>$intersectCount,
        'nearRoute'=>$nearCount,
        'atCruiseLevel'=>$cruiseCount,
        'available'=>$sigmetAvailable,
        'within100nm'=>count($timeRelevant),
        'outsideRouteEta'=>count(array_filter($hazards,fn($h)=>$h['timeRelation']==='outside_route_eta')),
        'outsideFlightWindow'=>count(array_filter($hazards,fn($h)=>$h['timeRelation']==='outside_flight_window')),
        'unknownTime'=>count(array_filter($timeRelevant,fn($h)=>$h['timeRelation']==='unknown')),
        'shown'=>count($hazards)
    ],
    'wafs'=>[
        'connected'=>false,
        'reason'=>'WIFS/WAFS gridded data requires authorized API access and GRIB2 processing.',
        'availableProducts'=>['wind_temperature','turbulence','icing','cumulonimbus'],
        'officialViewer'=>'https://aviationweather.gov/wafs/'
    ],
    'cache'=>['hit'=>false,'ageSeconds'=>0],
    'notes'=>[
        'METAR/TAF istasyonları rota boyunca yaklaşık 120 NM aralıklı, en fazla 8 ara meydan olacak şekilde temsilci olarak seçilir; kalkış/varış ayrıca eklenir.',
        'SIGMET yakınlığı rota geometrisine göre belirlenir; zaman ilgisi ayrıca tehlike bölgesinin yaklaşık rota geçiş penceresiyle karşılaştırılır.',
        'ETD ve tahmini EET yalnızca zaman bağlamı içindir; EET 450 kt varsayımıyla kaba tahmindir.',
        'WAFS gridleri bu sürümde doğrudan işlenmez; WIFS API erişimi gerekir.'
    ]
];

cachePut($cacheKey,$payload);
respond(200,$payload);