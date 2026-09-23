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
         WHERE ident LIKE :q OR name LIKE :q
         ORDER BY (ident = :exact) DESC, kind, ident
         LIMIT 12'
    );
    $stmt->execute(['q' => $like, 'exact' => $q]);
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
         WHERE ident LIKE :q OR name LIKE :q
         ORDER BY (ident = :exact) DESC, ident
         LIMIT 8'
    );
    $stmt->execute(['q' => $like, 'exact' => $q]);
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

$allowedLayers = ['airport', 'navaid', 'waypoint', 'airway', 'sid', 'star', 'airspace'];
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
