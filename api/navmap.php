<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=20, stale-while-revalidate=40');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clamp(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function normalizeLon(float $lon): float {
    while ($lon < -180.0) $lon += 360.0;
    while ($lon > 180.0) $lon -= 360.0;
    return $lon;
}

function circlePolygon(float $lon, float $lat, float $radiusNm, int $steps = 32): array {
    $earthRadiusNm = 3440.065;
    $angularDistance = max(0.0, $radiusNm) / $earthRadiusNm;
    $latRad = deg2rad($lat);
    $lonRad = deg2rad($lon);
    $ring = [];

    for ($i = 0; $i <= $steps; $i++) {
        $bearing = deg2rad(($i / $steps) * 360.0);
        $sinLat2 = sin($latRad) * cos($angularDistance)
            + cos($latRad) * sin($angularDistance) * cos($bearing);
        $lat2 = asin(max(-1.0, min(1.0, $sinLat2)));
        $lon2 = $lonRad + atan2(
            sin($bearing) * sin($angularDistance) * cos($latRad),
            cos($angularDistance) - sin($latRad) * sin($lat2)
        );

        $ring[] = [
            round(normalizeLon(rad2deg($lon2)), 6),
            round(rad2deg($lat2), 6),
        ];
    }

    return [
        'type' => 'Polygon',
        'coordinates' => [$ring],
    ];
}

function loadDbConfig(): array {
    // /home/<cpanel-user>/public_html/main/api -> /home/<cpanel-user>/data.php
    $homeRoot = dirname(dirname(dirname(__DIR__)));
    $path = $homeRoot . '/data.php';

    if (!is_file($path)) {
        respond(500, ['ok' => false, 'error' => 'Navdata DB yapılandırması bulunamadı.']);
    }

    $config = require $path;
    if (!is_array($config)) {
        respond(500, ['ok' => false, 'error' => 'Navdata DB yapılandırması geçersiz.']);
    }

    foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
        if (!array_key_exists($key, $config)) {
            respond(500, ['ok' => false, 'error' => 'Navdata DB yapılandırması eksik.']);
        }
    }

    return $config;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_mysql')) {
        respond(500, ['ok' => false, 'error' => 'PDO MySQL bu sunucuda aktif değil.']);
    }

    $cfg = loadDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['database']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        respond(500, ['ok' => false, 'error' => 'Navdata veritabanına bağlanılamadı.']);
    }

    return $pdo;
}

function emptyCollection(): array {
    return ['type' => 'FeatureCollection', 'features' => []];
}

function pointFeature(array $row): array {
    return [
        'type' => 'Feature',
        'id' => 'p-' . $row['id'],
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$row['lon'], (float)$row['lat']],
        ],
        'properties' => [
            'layer' => $row['kind'] === 'designatedpoint' ? 'waypoint' : $row['kind'],
            'id' => (int)$row['id'],
            'ident' => $row['ident'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'iata' => $row['iata'],
            'city' => $row['city'],
            'elevation_ft' => $row['elevation_ft'] !== null ? (float)$row['elevation_ft'] : null,
            'frequency' => $row['frequency_text'],
            'channel' => $row['channel'],
            'status' => $row['provider_status'],
            'type_code' => $row['type_code'] !== null ? (int)$row['type_code'] : null,
        ],
    ];
}

function routeFeature(array $row): ?array {
    $geometry = json_decode((string)$row['geometry'], true);
    if (!is_array($geometry) || !isset($geometry['type'])) return null;

    return [
        'type' => 'Feature',
        'id' => 'r-' . $row['geometry_id'] . '-' . $row['route_id'],
        'geometry' => $geometry,
        'properties' => [
            'layer' => $row['route_type'],
            'geometry_id' => (int)$row['geometry_id'],
            'route_id' => (int)$row['route_id'],
            'ident' => $row['ident'],
            'route_type' => $row['route_type'],
            'from_ident' => $row['from_ident'],
            'to_ident' => $row['to_ident'],
            'forward' => $row['forward'] !== null ? (int)$row['forward'] : null,
            'backward' => $row['backward'] !== null ? (int)$row['backward'] : null,
            'lower_text' => $row['lower_text'],
            'upper_text' => $row['upper_text'],
            'upper_unlimited' => (int)$row['upper_unlimited'],
        ],
    ];
}

function airspaceFeature(array $row): ?array {
    $geometry = json_decode((string)$row['geometry'], true);
    if (!is_array($geometry) || !isset($geometry['type'])) return null;

    return [
        'type' => 'Feature',
        'id' => 'a-' . $row['geometry_id'],
        'geometry' => $geometry,
        'properties' => [
            'layer' => 'airspace',
            'geometry_id' => (int)$row['geometry_id'],
            'airspace_id' => $row['airspace_id'] !== null ? (int)$row['airspace_id'] : null,
            'ident' => $row['ident'],
            'name' => $row['name'],
            'type_code' => $row['type_code'] !== null ? (int)$row['type_code'] : null,
            'local_type' => $row['local_type'] !== null ? (int)$row['local_type'] : null,
            'usage_code' => $row['usage_code'] !== null ? (int)$row['usage_code'] : null,
            'control_type' => $row['control_type'] !== null ? (int)$row['control_type'] : null,
            'activity' => $row['activity'] !== null ? (int)$row['activity'] : null,
            'lower_text' => $row['lower_text'],
            'upper_text' => $row['upper_text'],
            'upper_unlimited' => (int)$row['upper_unlimited'],
        ],
    ];
}

function notamFeature(array $row): ?array {
    $geometry = json_decode((string)$row['geometry'], true);
    if (!is_array($geometry) || !isset($geometry['type'])) return null;

    $geometrySource = (string)($row['geometry_source'] ?? 'faa-geometry');
    $radiusNm = isset($row['radius_nm']) && is_numeric((string)$row['radius_nm'])
        ? (float)$row['radius_nm']
        : null;

    if (($geometry['type'] ?? '') === 'Point' && $radiusNm !== null && $radiusNm > 0.0) {
        $coords = $geometry['coordinates'] ?? null;
        if (is_array($coords) && count($coords) >= 2) {
            $geometry = circlePolygon((float)$coords[0], (float)$coords[1], $radiusNm);

            if ($geometrySource === 'airport-location') {
                $geometrySource = 'airport-radius-circle';
            } elseif ($geometrySource === 'qline-coordinate') {
                $geometrySource = 'qline-radius-circle';
            } elseif ($geometrySource === 'faa-geometry') {
                $geometrySource = 'faa-radius-circle';
            } else {
                $geometrySource = 'derived-radius-circle';
            }
        }
    }

    $series = trim((string)($row['series'] ?? ''));
    $number = trim((string)($row['number'] ?? ''));
    $year = trim((string)($row['year'] ?? ''));
    $ident = trim($series . $number . ($year !== '' ? '/' . substr($year, -2) : ''));

    return [
        'type' => 'Feature',
        'id' => 'n-' . $row['nms_id'],
        'geometry' => $geometry,
        'properties' => [
            'layer' => 'notam',
            'nms_id' => $row['nms_id'],
            'ident' => $ident !== '' ? $ident : $row['nms_id'],
            'classification' => $row['classification'],
            'location' => $row['location'],
            'icao_location' => $row['icao_location'],
            'effective_start' => $row['effective_start'],
            'effective_end' => $row['effective_end'],
            'effective_end_raw' => $row['effective_end_raw'],
            'lower_limit' => $row['lower_limit'],
            'upper_limit' => $row['upper_limit'],
            'radius_nm' => $radiusNm,
            'text' => $row['notam_text'],
            'geometry_source' => $geometrySource,
        ],
    ];
}

function bboxGeometrySql(float $west, float $south, float $east, float $north, array &$params): string {
    if ($west <= $east) {
        $params['bbox'] = sprintf(
            'POLYGON((%.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F, %.8F %.8F))',
            $west, $south,
            $east, $south,
            $east, $north,
            $west, $north,
            $west, $south
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

$action = strtolower((string)($_GET['action'] ?? 'viewport'));
$pdo = db();

if ($action === 'health') {
    $counts = [];
    foreach ([
        'nav_points',
        'nav_routes',
        'nav_route_segments',
        'nav_route_memberships',
        'nav_route_availability',
        'nav_route_geometry',
        'nav_airspaces',
        'nav_airspace_geometry',
    ] as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    respond(200, ['ok' => true, 'counts' => $counts]);
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) < 2) {
        respond(400, ['ok' => false, 'error' => 'En az 2 karakter gir.']);
    }

    $like = '%' . $q . '%';
    $results = [];

    $stmt = $pdo->prepare(
        'SELECT id, kind, ident, name, lat, lon
         FROM nav_points
         WHERE ident LIKE :q1 OR name LIKE :q2
         ORDER BY (ident = :exact) DESC, kind, ident
         LIMIT 12'
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'exact' => $q]);
    foreach ($stmt as $row) {
        $results[] = [
            'kind' => $row['kind'] === 'designatedpoint' ? 'waypoint' : $row['kind'],
            'id' => (int)$row['id'],
            'ident' => $row['ident'],
            'name' => $row['name'],
            'lon' => (float)$row['lon'],
            'lat' => (float)$row['lat'],
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT id, ident, type
         FROM nav_routes
         WHERE ident LIKE :q
         ORDER BY (ident = :exact) DESC, type, ident
         LIMIT 8'
    );
    $stmt->execute(['q' => $like, 'exact' => $q]);
    foreach ($stmt as $row) {
        $results[] = [
            'kind' => $row['type'],
            'id' => (int)$row['id'],
            'ident' => $row['ident'],
            'name' => null,
            'lon' => null,
            'lat' => null,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT id, ident, name
         FROM nav_airspaces
         WHERE ident LIKE :q1 OR name LIKE :q2
         ORDER BY (ident = :exact) DESC, ident
         LIMIT 8'
    );
    $stmt->execute(['q1' => $like, 'q2' => $like, 'exact' => $q]);
    foreach ($stmt as $row) {
        $results[] = [
            'kind' => 'airspace',
            'id' => (int)$row['id'],
            'ident' => $row['ident'],
            'name' => $row['name'],
            'lon' => null,
            'lat' => null,
        ];
    }

    respond(200, ['ok' => true, 'results' => array_slice($results, 0, 24)]);
}

if ($action !== 'viewport') {
    respond(400, ['ok' => false, 'error' => 'Geçersiz action.']);
}

$zoom = isset($_GET['z']) && is_numeric($_GET['z']) ? (int)$_GET['z'] : 5;
$zoom = max(0, min(18, $zoom));

foreach (['west', 'south', 'east', 'north'] as $key) {
    if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) {
        respond(400, ['ok' => false, 'error' => "Eksik/geçersiz bbox: {$key}"]);
    }
}

$west = normalizeLon((float)$_GET['west']);
$east = normalizeLon((float)$_GET['east']);
$south = clamp((float)$_GET['south'], -85.0, 85.0);
$north = clamp((float)$_GET['north'], -85.0, 85.0);
if ($south > $north) [$south, $north] = [$north, $south];

$requestedLayers = array_filter(array_map(
    static fn(string $v): string => strtolower(trim($v)),
    explode(',', (string)($_GET['layers'] ?? 'airport,navaid,waypoint,airway,sid,star,airspace'))
));

$allowedLayers = ['airport', 'navaid', 'waypoint', 'airway', 'sid', 'star', 'airspace', 'notam'];
$layers = array_values(array_intersect($allowedLayers, $requestedLayers));
if (!$layers) {
    respond(200, [
        'ok' => true,
        'zoom' => $zoom,
        'data' => emptyCollection(),
        'counts' => [],
        'truncated' => false,
    ]);
}

$features = [];
$counts = array_fill_keys($allowedLayers, 0);
$truncated = false;

// Navdata is intentionally hidden below z5. Drawing the whole planet at once is
// not useful and would defeat viewport-based loading.
if ($zoom < 5) {
    respond(200, [
        'ok' => true,
        'zoom' => $zoom,
        'data' => emptyCollection(),
        'counts' => $counts,
        'truncated' => false,
        'hint' => 'Navdata z5 ve üzerinde yüklenir.',
    ]);
}

// POINTS
$pointKinds = [];
if (in_array('airport', $layers, true) && $zoom >= 5) $pointKinds[] = 'airport';
if (in_array('navaid', $layers, true) && $zoom >= 6) $pointKinds[] = 'navaid';
if (in_array('waypoint', $layers, true) && $zoom >= 8) $pointKinds[] = 'designatedpoint';

if ($pointKinds) {
    $whereLon = $west <= $east
        ? 'lon BETWEEN :west AND :east'
        : '(lon >= :west OR lon <= :east)';

    $kindPlaceholders = [];
    $params = [
        'south' => $south,
        'north' => $north,
        'west' => $west,
        'east' => $east,
    ];

    foreach ($pointKinds as $i => $kind) {
        $name = 'kind' . $i;
        $kindPlaceholders[] = ':' . $name;
        $params[$name] = $kind;
    }

    $sql = 'SELECT
                id, kind, ident, name, lat, lon, type_code, city, iata,
                elevation_ft, frequency_text, channel, provider_status
            FROM nav_points
            WHERE kind IN (' . implode(',', $kindPlaceholders) . ')
              AND lat BETWEEN :south AND :north
              AND ' . $whereLon . '
            LIMIT 16000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $pointCount = 0;
    while ($row = $stmt->fetch()) {
        $feature = pointFeature($row);
        $features[] = $feature;
        $counts[$feature['properties']['layer']]++;
        $pointCount++;
    }
    if ($pointCount >= 16000) $truncated = true;
}

// ROUTES
$routeTypes = [];
if (in_array('airway', $layers, true) && $zoom >= 5) $routeTypes[] = 'airway';
if (in_array('sid', $layers, true) && $zoom >= 8) $routeTypes[] = 'sid';
if (in_array('star', $layers, true) && $zoom >= 8) $routeTypes[] = 'star';

if ($routeTypes) {
    $params = [];
    $bboxExpr = bboxGeometrySql($west, $south, $east, $north, $params);
    $bboxExpr = sprintf($bboxExpr, 'rg.geom', 'rg.geom');

    $typePlaceholders = [];
    foreach ($routeTypes as $i => $type) {
        $name = 'rtype' . $i;
        $typePlaceholders[] = ':' . $name;
        $params[$name] = $type;
    }

    $sql = 'SELECT
                rg.id AS geometry_id,
                rg.from_ident,
                rg.to_ident,
                ST_AsGeoJSON(rg.geom, 6) AS geometry,
                r.id AS route_id,
                r.ident,
                r.type AS route_type,
                rm.forward,
                rm.backward,
                rm.lower_text,
                rm.upper_text,
                rm.upper_unlimited
            FROM nav_route_geometry rg
            JOIN nav_route_memberships rm ON rm.segment_id = rg.segment_id
            JOIN nav_routes r ON r.id = rm.route_id
            WHERE r.type IN (' . implode(',', $typePlaceholders) . ')
              AND r.suggested_min_zoom <= :zoom
              AND ' . $bboxExpr . '
            LIMIT 22000';

    $params['zoom'] = $zoom;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $routeCount = 0;
    while ($row = $stmt->fetch()) {
        $feature = routeFeature($row);
        if (!$feature) continue;
        $features[] = $feature;
        $counts[$feature['properties']['layer']]++;
        $routeCount++;
    }
    if ($routeCount >= 22000) $truncated = true;
}

// AIRSPACE
if (in_array('airspace', $layers, true) && $zoom >= 5) {
    $params = [];
    $bboxExpr = bboxGeometrySql($west, $south, $east, $north, $params);
    $bboxExpr = sprintf($bboxExpr, 'ag.geom', 'ag.geom');

    $sql = 'SELECT
                ag.id AS geometry_id,
                ag.airspace_id,
                ag.ident,
                ST_AsGeoJSON(ag.geom, 6) AS geometry,
                a.name,
                a.type_code,
                a.local_type,
                a.usage_code,
                a.control_type,
                a.activity,
                a.lower_text,
                a.upper_text,
                a.upper_unlimited
            FROM nav_airspace_geometry ag
            LEFT JOIN nav_airspaces a ON a.id = ag.airspace_id
            WHERE ' . $bboxExpr . '
            LIMIT 6000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $airspaceCount = 0;
    while ($row = $stmt->fetch()) {
        $feature = airspaceFeature($row);
        if (!$feature) continue;
        $features[] = $feature;
        $counts['airspace']++;
        $airspaceCount++;
    }
    if ($airspaceCount >= 6000) $truncated = true;
}

// NOTAM
if (in_array('notam', $layers, true) && $zoom >= 4) {
    $atRaw = trim((string)($_GET['at'] ?? ''));
    try {
        $at = $atRaw !== ''
            ? new DateTimeImmutable($atRaw, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable) {
        respond(400, ['ok' => false, 'error' => 'Geçersiz NOTAM zamanı. UTC ISO tarih/saat gönder.']);
    }
    $atSql = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $baseTimeWhere = "
              n.source = 'FAA_NMS'
              AND n.environment = :environment
              AND n.status <> 'cancelled'
              AND (n.effective_start IS NULL OR n.effective_start <= :at_start)
              AND (
                    UPPER(COALESCE(n.effective_end_raw, '')) = 'PERM'
                    OR n.effective_end IS NULL
                    OR n.effective_end >= :at_end
                  )";

    // 1) Use authoritative FAA geometry when available.
    $params = ['at_start' => $atSql, 'at_end' => $atSql, 'environment' => 'production'];
    $bboxExpr = bboxGeometrySql($west, $south, $east, $north, $params);
    $bboxExpr = sprintf($bboxExpr, 'n.geometry', 'n.geometry');

    $sql = 'SELECT
                n.nms_id, n.series, n.number, n.year, n.classification,
                n.location, n.icao_location, n.radius_nm, n.effective_start, n.effective_end,
                n.effective_end_raw, n.lower_limit, n.upper_limit, n.notam_text,
                ST_AsGeoJSON(n.geometry, 6) AS geometry,
                \'faa-geometry\' AS geometry_source
            FROM notams n
            WHERE ' . $baseTimeWhere . '
              AND n.geometry IS NOT NULL
              AND ' . $bboxExpr . '
            ORDER BY n.effective_start DESC
            LIMIT 5000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $notamCount = 0;
    $seenNotams = [];
    while ($row = $stmt->fetch()) {
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[(string)$row['nms_id']] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }

    // 2) Many Initial Load AIXM records have no GeoJSON geometry. Anchor those
    // airport NOTAMs to the matching navdata airport instead of silently hiding them.
    $whereLon = $west <= $east
        ? 'p.lon BETWEEN :west AND :east'
        : '(p.lon >= :west OR p.lon <= :east)';
    $fallbackParams = [
        'at_start' => $atSql,
        'at_end' => $atSql,
        'environment' => 'production',
        'south' => $south,
        'north' => $north,
        'west' => $west,
        'east' => $east,
    ];

    $fallbackSql = 'SELECT
                n.nms_id, n.series, n.number, n.year, n.classification,
                n.location, n.icao_location, n.radius_nm, n.effective_start, n.effective_end,
                n.effective_end_raw, n.lower_limit, n.upper_limit, n.notam_text,
                JSON_OBJECT(
                    \'type\', \'Point\',
                    \'coordinates\', JSON_ARRAY(p.lon, p.lat)
                ) AS geometry,
                \'airport-location\' AS geometry_source
            FROM notams n
            JOIN nav_points p
              ON p.kind = \'airport\'
             AND (
                    UPPER(p.ident) = UPPER(NULLIF(n.icao_location, \'\'))
                    OR UPPER(p.ident) = UPPER(NULLIF(n.location, \'\'))
                 )
            WHERE ' . $baseTimeWhere . '
              AND n.geometry IS NULL
              AND p.lat BETWEEN :south AND :north
              AND ' . $whereLon . '
            ORDER BY n.effective_start DESC
            LIMIT 5000';

    $fallbackStmt = $pdo->prepare($fallbackSql);
    $fallbackStmt->execute($fallbackParams);
    while ($row = $fallbackStmt->fetch()) {
        $id = (string)$row['nms_id'];
        if (isset($seenNotams[$id])) continue;
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[$id] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }

    // 3) If neither FAA GeoJSON nor airport location is available, derive a
    // point from the standard NOTAM coordinate token found in coordinates_raw
    // or the Q-line text. Supports DDMMNDDDMME and DDMMSSNDDDMMSS E forms.
    $coordParams = [
        'at_start' => $atSql,
        'at_end' => $atSql,
        'environment' => 'production',
        'south' => $south,
        'north' => $north,
        'west' => $west,
        'east' => $east,
    ];

    $coordLonWhere = $west <= $east
        ? 'q.lon BETWEEN :west AND :east'
        : '(q.lon >= :west OR q.lon <= :east)';

    $coordTokenExpr = "REGEXP_SUBSTR(
        UPPER(CONCAT_WS(' ', COALESCE(n.coordinates_raw, ''), COALESCE(n.notam_text, ''))),
        '([0-9]{6}[NS][0-9]{7}[EW]|[0-9]{4}[NS][0-9]{5}[EW])'
    )";

    $coordSql = 'SELECT
            q.nms_id, q.series, q.number, q.year, q.classification,
            q.location, q.icao_location, q.radius_nm, q.effective_start, q.effective_end,
            q.effective_end_raw, q.lower_limit, q.upper_limit, q.notam_text,
            JSON_OBJECT(
                \'type\', \'Point\',
                \'coordinates\', JSON_ARRAY(q.lon, q.lat)
            ) AS geometry,
            \'qline-coordinate\' AS geometry_source
        FROM (
            SELECT
                p.*,
                CASE
                    WHEN LENGTH(p.coord_token) = 11 THEN
                        (
                            CAST(SUBSTRING(p.coord_token, 1, 2) AS DECIMAL(10,6))
                            + CAST(SUBSTRING(p.coord_token, 3, 2) AS DECIMAL(10,6)) / 60
                        ) * IF(SUBSTRING(p.coord_token, 5, 1) = \'S\', -1, 1)
                    WHEN LENGTH(p.coord_token) = 15 THEN
                        (
                            CAST(SUBSTRING(p.coord_token, 1, 2) AS DECIMAL(10,6))
                            + CAST(SUBSTRING(p.coord_token, 3, 2) AS DECIMAL(10,6)) / 60
                            + CAST(SUBSTRING(p.coord_token, 5, 2) AS DECIMAL(10,6)) / 3600
                        ) * IF(SUBSTRING(p.coord_token, 7, 1) = \'S\', -1, 1)
                    ELSE NULL
                END AS lat,
                CASE
                    WHEN LENGTH(p.coord_token) = 11 THEN
                        (
                            CAST(SUBSTRING(p.coord_token, 6, 3) AS DECIMAL(10,6))
                            + CAST(SUBSTRING(p.coord_token, 9, 2) AS DECIMAL(10,6)) / 60
                        ) * IF(SUBSTRING(p.coord_token, 11, 1) = \'W\', -1, 1)
                    WHEN LENGTH(p.coord_token) = 15 THEN
                        (
                            CAST(SUBSTRING(p.coord_token, 8, 3) AS DECIMAL(10,6))
                            + CAST(SUBSTRING(p.coord_token, 11, 2) AS DECIMAL(10,6)) / 60
                            + CAST(SUBSTRING(p.coord_token, 13, 2) AS DECIMAL(10,6)) / 3600
                        ) * IF(SUBSTRING(p.coord_token, 15, 1) = \'W\', -1, 1)
                    ELSE NULL
                END AS lon
            FROM (
                SELECT
                    n.nms_id, n.series, n.number, n.year, n.classification,
                    n.location, n.icao_location, n.radius_nm, n.effective_start, n.effective_end,
                    n.effective_end_raw, n.lower_limit, n.upper_limit, n.notam_text,
                    ' . $coordTokenExpr . ' AS coord_token
                FROM notams n
                WHERE ' . $baseTimeWhere . '
                  AND n.geometry IS NULL
            ) p
            WHERE p.coord_token IS NOT NULL AND p.coord_token <> \'\'
        ) q
        WHERE q.lat BETWEEN :south AND :north
          AND ' . $coordLonWhere . '
        ORDER BY q.effective_start DESC
        LIMIT 5000';

    $coordStmt = $pdo->prepare($coordSql);
    $coordStmt->execute($coordParams);
    while ($row = $coordStmt->fetch()) {
        $id = (string)$row['nms_id'];
        if (isset($seenNotams[$id])) continue;
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[$id] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }

    if ($notamCount >= 5000) $truncated = true;
}

respond(200, [
    'ok' => true,
    'zoom' => $zoom,
    'bbox' => compact('west', 'south', 'east', 'north'),
    'data' => [
        'type' => 'FeatureCollection',
        'features' => $features,
    ],
    'counts' => $counts,
    'total' => count($features),
    'truncated' => $truncated,
]);
