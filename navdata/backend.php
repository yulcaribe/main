<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function navdataPath(): string {
    $env = trim((string)getenv('NAVDATA_SQLITE_PATH'));
    if ($env !== '') return $env;

    $mainRoot = dirname(__DIR__);
    $publicHtml = dirname($mainRoot);

    $candidates = [
        $publicHtml . DIRECTORY_SEPARATOR . 'navdata_core.sqlite',
        $mainRoot . DIRECTORY_SEPARATOR . 'navdata_core.sqlite',
        $mainRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'navdata_core.sqlite',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    return $candidates[0];
}

function requireOptionalKey(): void {
    $expected = trim((string)getenv('NAVDATA_READ_KEY'));
    if ($expected === '') return;

    $provided = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_NAVDATA_KEY'] ?? ''));
    if ($provided === '' || !hash_equals($expected, $provided)) {
        respond(401, ['ok' => false, 'error' => 'Unauthorized']);
    }
}

function tableExists(SQLite3 $db, string $name): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE type='table' AND name=:name");
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $row = $stmt->execute()?->fetchArray(SQLITE3_ASSOC);
    return (int)($row['n'] ?? 0) > 0;
}

function scalar(SQLite3 $db, string $sql): mixed {
    $result = $db->querySingle($sql, true);
    if (!is_array($result) || !$result) return null;
    return array_values($result)[0] ?? null;
}

function rows(SQLite3Result $result): array {
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

function bindLimit(SQLite3Stmt $stmt, int $limit): void {
    $stmt->bindValue(':limit', max(1, min(500, $limit)), SQLITE3_INTEGER);
}

requireOptionalKey();

if (!class_exists('SQLite3')) {
    respond(500, ['ok' => false, 'error' => 'PHP SQLite3 extension is not enabled.']);
}

$path = navdataPath();
if (!is_file($path)) {
    respond(503, [
        'ok' => false,
        'error' => 'navdata_core.sqlite bulunamadı.',
        'expectedDefaultPath' => $path,
        'hint' => 'Dosyayı public_html/navdata_core.sqlite olarak yükle veya NAVDATA_SQLITE_PATH tanımla.'
    ]);
}

try {
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'SQLite açılamadı.', 'detail' => $e->getMessage()]);
}

$required = ['metadata','points','routes','route_segments','route_memberships','route_geometry','airspaces','airspace_geometry'];
foreach ($required as $table) {
    if (!tableExists($db, $table)) {
        respond(500, ['ok' => false, 'error' => 'Beklenen core tablo eksik.', 'table' => $table]);
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? 'summary')));

if ($action === 'summary') {
    $pointKinds = rows($db->query("SELECT kind, COUNT(*) AS count FROM points GROUP BY kind ORDER BY count DESC"));
    $routeTypes = rows($db->query("SELECT type, COUNT(*) AS routes FROM routes GROUP BY type ORDER BY routes DESC"));

    $counts = [];
    foreach (['points','routes','route_segments','route_memberships','route_availability','route_geometry','airspaces','airspace_geometry'] as $table) {
        if (tableExists($db, $table)) $counts[$table] = (int)scalar($db, "SELECT COUNT(*) FROM " . $table);
    }

    $metadata = [];
    $res = $db->query("SELECT key, value FROM metadata ORDER BY key");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $metadata[$row['key']] = $row['value'];

    respond(200, [
        'ok' => true,
        'action' => 'summary',
        'database' => [
            'file' => basename($path),
            'sizeBytes' => filesize($path),
            'modifiedUtc' => gmdate('c', (int)filemtime($path)),
            'protectedByKey' => trim((string)getenv('NAVDATA_READ_KEY')) !== '',
        ],
        'metadata' => $metadata,
        'counts' => $counts,
        'pointKinds' => $pointKinds,
        'routeTypes' => $routeTypes,
    ]);
}

if ($action === 'route') {
    $ident = strtoupper(trim((string)($_GET['ident'] ?? '')));
    if ($ident === '' || strlen($ident) > 32) respond(400, ['ok' => false, 'error' => 'ident gerekli.']);
    $limit = (int)($_GET['limit'] ?? 200);

    $stmt = $db->prepare("
        SELECT
            r.id AS route_id, r.ident AS route_ident, r.type AS route_type, r.suggested_min_zoom,
            rm.id AS membership_id, rm.member_index, rm.forward, rm.backward,
            rm.lower_mode, rm.lower_alt, rm.lower_text,
            rm.upper_mode, rm.upper_alt, rm.upper_text, rm.upper_unlimited,
            s.id AS segment_id, s.source_seq,
            s.from_ident, s.from_kind, s.to_ident, s.to_kind,
            s.terrain_elev, s.corridor5_elev, s.water_fraction
        FROM routes r
        JOIN route_memberships rm ON rm.route_id=r.id
        JOIN route_segments s ON s.id=rm.segment_id
        WHERE UPPER(r.ident)=:ident
        ORDER BY r.type, r.id, rm.id
        LIMIT :limit
    ");
    $stmt->bindValue(':ident', $ident, SQLITE3_TEXT);
    bindLimit($stmt, $limit);
    $data = rows($stmt->execute());

    respond(200, ['ok' => true, 'action' => 'route', 'ident' => $ident, 'count' => count($data), 'rows' => $data]);
}

if ($action === 'point') {
    $ident = strtoupper(trim((string)($_GET['ident'] ?? '')));
    if ($ident === '' || strlen($ident) > 32) respond(400, ['ok' => false, 'error' => 'ident gerekli.']);
    $limit = (int)($_GET['limit'] ?? 100);

    $stmt = $db->prepare("
        SELECT id, kind, ident, name, lat, lon, type_code, city, iata, elevation_ft,
               declination_deg, suggested_min_zoom
        FROM points
        WHERE UPPER(ident)=:ident
        ORDER BY kind, id
        LIMIT :limit
    ");
    $stmt->bindValue(':ident', $ident, SQLITE3_TEXT);
    bindLimit($stmt, $limit);
    $data = rows($stmt->execute());

    respond(200, ['ok' => true, 'action' => 'point', 'ident' => $ident, 'count' => count($data), 'rows' => $data]);
}

if ($action === 'sample') {
    $type = strtolower(trim((string)($_GET['type'] ?? 'sid')));
    if (!preg_match('/^[a-z0-9_-]{1,24}$/', $type)) respond(400, ['ok' => false, 'error' => 'Geçersiz type.']);
    $limit = (int)($_GET['limit'] ?? 50);

    $stmt = $db->prepare("
        SELECT r.id, r.ident, r.type, r.suggested_min_zoom, COUNT(rm.id) AS memberships
        FROM routes r
        LEFT JOIN route_memberships rm ON rm.route_id=r.id
        WHERE LOWER(r.type)=:type
        GROUP BY r.id, r.ident, r.type, r.suggested_min_zoom
        ORDER BY memberships DESC, r.ident
        LIMIT :limit
    ");
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    bindLimit($stmt, $limit);
    $data = rows($stmt->execute());

    respond(200, ['ok' => true, 'action' => 'sample', 'type' => $type, 'count' => count($data), 'rows' => $data]);
}

if ($action === 'near') {
    $lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
    $lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
    $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_FLOAT);
    $limit = (int)($_GET['limit'] ?? 200);

    if ($lat === false || $lat === null || $lon === false || $lon === null) {
        respond(400, ['ok' => false, 'error' => 'lat ve lon gerekli.']);
    }
    $delta = ($delta === false || $delta === null) ? 0.5 : max(0.01, min(5.0, (float)$delta));

    $stmt = $db->prepare("
        SELECT id, kind, ident, name, lat, lon, suggested_min_zoom
        FROM points
        WHERE lat BETWEEN :minLat AND :maxLat
          AND lon BETWEEN :minLon AND :maxLon
        ORDER BY kind, ident
        LIMIT :limit
    ");
    $stmt->bindValue(':minLat', (float)$lat - $delta, SQLITE3_FLOAT);
    $stmt->bindValue(':maxLat', (float)$lat + $delta, SQLITE3_FLOAT);
    $stmt->bindValue(':minLon', (float)$lon - $delta, SQLITE3_FLOAT);
    $stmt->bindValue(':maxLon', (float)$lon + $delta, SQLITE3_FLOAT);
    bindLimit($stmt, $limit);
    $data = rows($stmt->execute());

    respond(200, [
        'ok' => true, 'action' => 'near',
        'center' => ['lat' => (float)$lat, 'lon' => (float)$lon],
        'delta' => $delta, 'count' => count($data), 'rows' => $data
    ]);
}

respond(400, [
    'ok' => false,
    'error' => 'Bilinmeyen action.',
    'actions' => [
        'summary',
        'route&ident=T723',
        'point&ident=LTAI',
        'sample&type=sid',
        'sample&type=star',
        'sample&type=airway',
        'near&lat=36.90&lon=30.80&delta=0.5'
    ]
]);
