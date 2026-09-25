<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, stale-while-revalidate=90');

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

function notamCoordinateRegex(): string {
    // Compact ICAO coordinates, including decimal minute/second variants seen
    // in E-text (e.g. 4100.020N02913.030E or 364313.27N0284719.24E).
    return '/(?:[0-9]{6}(?:\.[0-9]+)?[NS][0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][0-9]{5}(?:\.[0-9]+)?[EW])/i';
}

function parseNotamCoordinate(string $token): ?array {
    $token = strtoupper(trim($token));

    if (preg_match('/^([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2}(?:\.[0-9]+)?)([EW])$/', $token, $m)) {
        $lat = (float)$m[1] + (float)$m[2] / 60.0;
        $lon = (float)$m[4] + (float)$m[5] / 60.0;
        if ($m[3] === 'S') $lat *= -1;
        if ($m[6] === 'W') $lon *= -1;
        return [$lon, $lat];
    }

    if (preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([NS])([0-9]{3})([0-9]{2})([0-9]{2}(?:\.[0-9]+)?)([EW])$/', $token, $m)) {
        $lat = (float)$m[1] + (float)$m[2] / 60.0 + (float)$m[3] / 3600.0;
        $lon = (float)$m[5] + (float)$m[6] / 60.0 + (float)$m[7] / 3600.0;
        if ($m[4] === 'S') $lat *= -1;
        if ($m[8] === 'W') $lon *= -1;
        return [$lon, $lat];
    }

    return null;
}

function extractNotamCoordinates(?string $text, bool $dedupe = true): array {
    $text = strtoupper(trim((string)$text));
    if ($text === '') return [];
    if (!preg_match_all(notamCoordinateRegex(), $text, $matches)) return [];

    $coords = [];
    $seen = [];
    foreach ($matches[0] as $token) {
        $coord = parseNotamCoordinate((string)$token);
        if ($coord === null) continue;

        if (!$dedupe) {
            $coords[] = $coord;
            continue;
        }

        $key = sprintf('%.6F,%.6F', $coord[0], $coord[1]);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $coords[] = $coord;
        }
    }
    return $coords;
}

function polygonFromCoordinateList(array $coords): ?array {
    if (count($coords) < 3) return null;

    $ring = array_values($coords);
    $first = $ring[0];
    $last = $ring[count($ring) - 1];

    if (abs((float)$first[0] - (float)$last[0]) > 0.000001
        || abs((float)$first[1] - (float)$last[1]) > 0.000001) {
        $ring[] = $first;
    }

    return ['type' => 'Polygon', 'coordinates' => [$ring]];
}

function radiusToNm(float $value, string $unit): ?float {
    $unit = strtoupper(trim($unit));
    if ($value <= 0.0) return null;
    return match ($unit) {
        'NM' => $value,
        'KM' => $value / 1.852,
        'M' => $value / 1852.0,
        default => null,
    };
}

function destinationPoint(float $lon, float $lat, float $bearingDeg, float $distanceNm): array {
    $earthRadiusNm = 3440.065;
    $angularDistance = max(0.0, $distanceNm) / $earthRadiusNm;
    $bearing = deg2rad($bearingDeg);
    $lat1 = deg2rad($lat);
    $lon1 = deg2rad($lon);

    $sinLat2 = sin($lat1) * cos($angularDistance)
        + cos($lat1) * sin($angularDistance) * cos($bearing);
    $lat2 = asin(max(-1.0, min(1.0, $sinLat2)));
    $lon2 = $lon1 + atan2(
        sin($bearing) * sin($angularDistance) * cos($lat1),
        cos($angularDistance) - sin($lat1) * sin($lat2)
    );

    return [
        round(normalizeLon(rad2deg($lon2)), 6),
        round(rad2deg($lat2), 6),
    ];
}

function initialBearingDeg(array $from, array $to): float {
    $lat1 = deg2rad((float)$from[1]);
    $lat2 = deg2rad((float)$to[1]);
    $dLon = deg2rad((float)$to[0] - (float)$from[0]);

    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    $bearing = rad2deg(atan2($y, $x));
    return fmod($bearing + 360.0, 360.0);
}

function corridorGeometry(array $coords, float $halfWidthNm): ?array {
    if (count($coords) < 2 || $halfWidthNm <= 0.0) return null;

    $polygons = [];
    for ($i = 0; $i < count($coords) - 1; $i++) {
        $a = $coords[$i];
        $b = $coords[$i + 1];
        if (abs((float)$a[0] - (float)$b[0]) < 0.000001
            && abs((float)$a[1] - (float)$b[1]) < 0.000001) {
            continue;
        }

        $bearing = initialBearingDeg($a, $b);
        $left = $bearing - 90.0;
        $right = $bearing + 90.0;

        $aLeft = destinationPoint((float)$a[0], (float)$a[1], $left, $halfWidthNm);
        $bLeft = destinationPoint((float)$b[0], (float)$b[1], $left, $halfWidthNm);
        $bRight = destinationPoint((float)$b[0], (float)$b[1], $right, $halfWidthNm);
        $aRight = destinationPoint((float)$a[0], (float)$a[1], $right, $halfWidthNm);

        $polygons[] = [[$aLeft, $bLeft, $bRight, $aRight, $aLeft]];
    }

    if (!$polygons) return null;
    if (count($polygons) === 1) {
        return ['type' => 'Polygon', 'coordinates' => $polygons[0]];
    }

    return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
}

function notamQSubject(array $row): string {
    $q = strtoupper(trim((string)($row['selection_code'] ?? '')));
    if (preg_match('/^Q([A-Z]{2})[A-Z]{2}$/', $q, $m)) return $m[1];
    return '';
}

function notamSemantic(array $row): array {
    $subject = notamQSubject($row);

    $semantic = match ($subject) {
        'RD', 'RP', 'RR', 'RT' => 'RESTRICTED_AIRSPACE',
        'WY' => 'AERIAL_SURVEY',
        'WE' => 'EXERCISE',
        'WF' => 'AIR_REFUELING',
        'WM' => 'FIRING',
        'WU' => 'UAV_ACTIVITY',
        'WG', 'WL', 'WP', 'WT' => 'AERIAL_SPORT_ACTIVITY',
        'OB' => 'OBSTACLE',
        'AC' => 'CONTROLLED_AIRSPACE',
        'MR' => 'RUNWAY',
        default => 'OTHER',
    };

    $displayGroup = match ($subject) {
        'WG', 'WL', 'WP', 'WT' => 'AERIAL_SPORT',
        'RD', 'RP', 'RR', 'RT' => 'RESTRICTED_AIRSPACE',
        'WY' => 'AERIAL_SURVEY',
        'WE', 'WF', 'WM', 'WU' => 'TRAINING_MILITARY',
        default => 'OTHER',
    };

    return [
        'q_subject' => $subject,
        'semantic_class' => $semantic,
        'display_group' => $displayGroup,
    ];
}

function notamTextSpatialSegment(string $text): ?string {
    $patterns = [
        '/\bWI(?:THIN)?\s+AREA\b\s*:?\s*/i',
        '/\bAREA\s+BOUNDED\s+BY\b\s*:?\s*/i',
        '/\bBOUNDED\s+BY\b\s*:?\s*/i',
        '/\bBOUNDARY\b\s*:?\s*/i',
        '/\bLATERAL\s+LIMITS?\b\s*:?\s*/i',
        '/\bAREA\b\s*:?\s*/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
        $marker = (string)$m[0][0];
        $offset = (int)$m[0][1] + strlen($marker);
        $segment = substr($text, $offset);

        if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b)/i', $segment, $stop, PREG_OFFSET_CAPTURE)) {
            $segment = substr($segment, 0, (int)$stop[0][1]);
        }

        return $segment;
    }

    return null;
}

function notamExplicitPolygon(array $row): ?array {
    $text = (string)($row['notam_text'] ?? '');
    $segment = notamTextSpatialSegment($text);

    // Some Turkish NOTAMs use "WI:" or plain "COORDINATES:" instead of
    // "AREA:". Only accept those weaker markers for Q-code families that are
    // themselves spatial-area activities. This avoids turning obstacle point
    // lists or administrative coordinates into accidental polygons.
    if ($segment === null) {
        $semantic = notamSemantic($row);
        $areaSemantic = in_array(
            $semantic['semantic_class'] ?? '',
            [
                'RESTRICTED_AIRSPACE',
                'AERIAL_SURVEY',
                'EXERCISE',
                'AIR_REFUELING',
                'FIRING',
                'UAV_ACTIVITY',
                'AERIAL_SPORT_ACTIVITY',
                'CONTROLLED_AIRSPACE',
            ],
            true
        );

        if ($areaSemantic) {
            foreach (['/\bWI\s*:\s*/i', '/\bCOORDINATES?\s*:\s*/i'] as $pattern) {
                if (!preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) continue;
                $marker = (string)$m[0][0];
                $offset = (int)$m[0][1] + strlen($marker);
                $segment = substr($text, $offset);
                if (preg_match('/(?:\r?\n|\s)(?:F\)|G\)|SCHEDULE\b|REMARKS?\b|RMK\b|NOTE\b|VERTICAL\s+LIMITS?\b)/i', $segment, $stop, PREG_OFFSET_CAPTURE)) {
                    $segment = substr($segment, 0, (int)$stop[0][1]);
                }
                break;
            }
        }
    }

    if ($segment === null) return null;

    $coords = extractNotamCoordinates($segment, true);
    $polygon = polygonFromCoordinateList($coords);
    if ($polygon === null) return null;

    return [
        'geometry' => $polygon,
        'source' => 'e-text-polygon',
        'render_type' => 'AREA',
        'confidence' => 'EXPLICIT',
        'explicit_radius_nm' => null,
    ];
}

function notamExplicitCircle(array $row): ?array {
    $text = strtoupper((string)($row['notam_text'] ?? ''));
    if ($text === '' || stripos($text, 'RADIUS') === false) return null;

    $coordPattern = '(?:[0-9]{6}(?:\.[0-9]+)?[NS][0-9]{7}(?:\.[0-9]+)?[EW]|[0-9]{4}(?:\.[0-9]+)?[NS][0-9]{5}(?:\.[0-9]+)?[EW])';
    $patterns = [
        '/(' . $coordPattern . ').{0,180}?\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\b/is',
        '/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+RADIUS.{0,180}?(' . $coordPattern . ')/is',
        '/\bRADIUS(?:\s+OF)?\s*([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M).{0,180}?(' . $coordPattern . ')/is',
    ];

    foreach ($patterns as $index => $pattern) {
        if (!preg_match($pattern, $text, $m)) continue;

        if ($index === 0) {
            $coordToken = $m[1];
            $radiusValue = (float)$m[2];
            $unit = $m[3];
        } else {
            $radiusValue = (float)$m[1];
            $unit = $m[2];
            $coordToken = $m[3];
        }

        $coord = parseNotamCoordinate($coordToken);
        $radiusNm = radiusToNm($radiusValue, $unit);
        if ($coord === null || $radiusNm === null) continue;

        return [
            'geometry' => circlePolygon((float)$coord[0], (float)$coord[1], $radiusNm, 60),
            'source' => 'e-text-circle',
            'render_type' => 'CIRCLE',
            'confidence' => 'EXPLICIT',
            'explicit_radius_nm' => round($radiusNm, 3),
        ];
    }

    return null;
}

function notamExplicitCorridor(array $row): ?array {
    $text = strtoupper((string)($row['notam_text'] ?? ''));
    if ($text === '' || stripos($text, 'EITHER SIDE') === false) return null;

    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(NM|KM|M)\s+EITHER\s+SIDE\s+OF(?:\s+A)?\s+LINE/i', $text, $m)) {
        return null;
    }

    $halfWidthNm = radiusToNm((float)$m[1], $m[2]);
    if ($halfWidthNm === null) return null;

    $coords = extractNotamCoordinates($text, false);
    if (count($coords) < 2) return null;

    $geometry = corridorGeometry($coords, $halfWidthNm);
    if ($geometry === null) return null;

    return [
        'geometry' => $geometry,
        'source' => 'e-text-corridor',
        'render_type' => 'CORRIDOR',
        'confidence' => 'EXPLICIT',
        'explicit_radius_nm' => round($halfWidthNm, 3),
    ];
}

function resolveNotamExplicitGeometry(array $row): ?array {
    // A real polygon boundary beats every derived shape. Corridor and circle
    // are only used when the NOTAM explicitly defines those spatial forms.
    $polygon = notamExplicitPolygon($row);
    if ($polygon !== null) return $polygon;

    $corridor = notamExplicitCorridor($row);
    if ($corridor !== null) return $corridor;

    return notamExplicitCircle($row);
}

function notamPointIsRenderable(array $row, string $geometrySource, array $semantic): bool {
    if (($semantic['semantic_class'] ?? '') === 'OBSTACLE') return true;

    $scope = strtoupper((string)($row['scope'] ?? ''));
    if (in_array($geometrySource, ['airport-location', 'faa-geometry'], true) && str_contains($scope, 'A')) return true;

    return false;
}

function notamCategory(array $row, ?array $semantic = null): string {
    $semantic ??= notamSemantic($row);
    return match ($semantic['semantic_class'] ?? 'OTHER') {
        'RESTRICTED_AIRSPACE' => 'AIRSPACE',
        'AERIAL_SURVEY' => 'AIRSPACE',
        'EXERCISE', 'AIR_REFUELING', 'FIRING' => 'AIRSPACE',
        'UAV_ACTIVITY' => 'UAV',
        'AERIAL_SPORT_ACTIVITY' => 'AIRSPACE',
        'OBSTACLE' => 'OBSTACLE',
        'RUNWAY' => 'RWY',
        'CONTROLLED_AIRSPACE' => 'AIRSPACE',
        default => 'GENERAL',
    };
}

function navmapCacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'yulcaribe_navmap_v2';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

function navmapNotamSyncVersion(PDO $pdo, string $environment): string {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(DATE_FORMAT(last_successful_sync, '%Y%m%d%H%i%s'), 'none')
         FROM notam_sync_state
         WHERE source = 'FAA_NMS' AND environment = :environment
         LIMIT 1"
    );
    $stmt->execute(['environment' => $environment]);
    return (string)($stmt->fetchColumn() ?: 'none');
}

function navmapCacheRead(string $path, int $maxAgeSeconds = 900): ?array {
    if (!is_file($path)) return null;

    $mtime = @filemtime($path);
    if ($mtime === false || time() - $mtime > $maxAgeSeconds) return null;

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function navmapCacheWrite(string $path, array $payload): void {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }

    $dir = dirname($path);
    $cleanupMarker = $dir . DIRECTORY_SEPARATOR . '.cleanup';
    $markerAge = is_file($cleanupMarker) ? time() - (int)@filemtime($cleanupMarker) : PHP_INT_MAX;
    if ($markerAge > 3600) {
        @touch($cleanupMarker);
        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . 'notam_*.json') as $candidate) {
            $mtime = @filemtime($candidate);
            if ($mtime !== false && time() - $mtime > 21600) @unlink($candidate);
        }
    }
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
    $geometry = normalizeNotamGeometry($geometry);
    if ($geometry === null) return null;

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


function normalizeNotamGeometry(array $geometry): ?array {
    $type = (string)($geometry['type'] ?? '');
    if ($type !== 'GeometryCollection') return $type !== '' ? $geometry : null;

    $items = $geometry['geometries'] ?? null;
    if (!is_array($items) || !$items) return null;

    $points = [];
    $lines = [];
    $polygons = [];

    $collect = static function (?array $g) use (&$points, &$lines, &$polygons): void {
        if (!is_array($g)) return;
        $t = (string)($g['type'] ?? '');
        $coords = $g['coordinates'] ?? null;

        if ($t === 'Point' && is_array($coords)) {
            $points[] = $coords;
        } elseif ($t === 'MultiPoint' && is_array($coords)) {
            foreach ($coords as $p) if (is_array($p)) $points[] = $p;
        } elseif ($t === 'LineString' && is_array($coords)) {
            $lines[] = $coords;
        } elseif ($t === 'MultiLineString' && is_array($coords)) {
            foreach ($coords as $line) if (is_array($line)) $lines[] = $line;
        } elseif ($t === 'Polygon' && is_array($coords)) {
            $polygons[] = $coords;
        } elseif ($t === 'MultiPolygon' && is_array($coords)) {
            foreach ($coords as $polygon) if (is_array($polygon)) $polygons[] = $polygon;
        }
    };

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $normalized = normalizeNotamGeometry($item);
        $collect($normalized);
    }

    // For mixed FAA collections prefer the highest-dimensional affected shape.
    // A point bundled with a polygon is normally a reference/anchor, not a
    // second affected area.
    if ($polygons) {
        return count($polygons) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }
    if ($lines) {
        return count($lines) === 1
            ? ['type' => 'LineString', 'coordinates' => $lines[0]]
            : ['type' => 'MultiLineString', 'coordinates' => $lines];
    }
    if ($points) {
        return count($points) === 1
            ? ['type' => 'Point', 'coordinates' => $points[0]]
            : ['type' => 'MultiPoint', 'coordinates' => $points];
    }

    return null;
}

function notamSemanticNeedsText(array $row): bool {
    $semantic = notamSemantic($row);
    return in_array(
        $semantic['semantic_class'] ?? '',
        [
            'RESTRICTED_AIRSPACE',
            'AERIAL_SURVEY',
            'EXERCISE',
            'AIR_REFUELING',
            'FIRING',
            'UAV_ACTIVITY',
            'AERIAL_SPORT_ACTIVITY',
            'CONTROLLED_AIRSPACE',
            'OBSTACLE',
        ],
        true
    );
}

function hydrateNotamTexts(PDO $pdo, array $rows): array {
    $ids = [];

    foreach ($rows as $row) {
        if (!notamSemanticNeedsText($row)) continue;

        $geometry = json_decode((string)($row['geometry'] ?? ''), true);
        $geometry = is_array($geometry) ? normalizeNotamGeometry($geometry) : null;
        $type = (string)($geometry['type'] ?? '');

        // Authoritative polygon/line geometry does not need E-text to draw.
        if ($type !== '' && !in_array($type, ['Point', 'MultiPoint'], true)) continue;

        $id = trim((string)($row['nms_id'] ?? ''));
        if ($id !== '') $ids[$id] = true;
    }

    if (!$ids) return $rows;

    $texts = [];
    foreach (array_chunk(array_keys($ids), 400) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(
            "SELECT nms_id, notam_text
             FROM notams
             WHERE source = 'FAA_NMS'
               AND environment = 'production'
               AND nms_id IN ($placeholders)"
        );
        $stmt->execute($chunk);
        while ($r = $stmt->fetch()) {
            $texts[(string)$r['nms_id']] = $r['notam_text'];
        }
    }

    foreach ($rows as &$row) {
        $id = (string)($row['nms_id'] ?? '');
        $row['notam_text'] = $texts[$id] ?? null;
    }
    unset($row);

    return $rows;
}

function notamFeature(array $row): ?array {
    $geometry = json_decode((string)$row['geometry'], true);
    if (!is_array($geometry) || !isset($geometry['type'])) return null;
    $geometry = normalizeNotamGeometry($geometry);
    if ($geometry === null) return null;

    $geometrySource = (string)($row['geometry_source'] ?? 'faa-geometry');
    $qlineRadiusNm = isset($row['radius_nm']) && is_numeric((string)$row['radius_nm'])
        ? (float)$row['radius_nm']
        : null;

    $semantic = notamSemantic($row);
    $explicit = null;
    $geometryType = (string)($geometry['type'] ?? '');

    // FAA non-point geometry is authoritative. A point is only an anchor:
    // first try the explicit spatial definition from E-text. Q-line radius is
    // deliberately NOT converted into display geometry.
    if ($geometryType === 'Point') {
        $explicit = resolveNotamExplicitGeometry($row);
        if ($explicit !== null) {
            $geometry = $explicit['geometry'];
            $geometrySource = $explicit['source'];
            $geometryType = (string)$geometry['type'];
        } else {
            // Point-like obstacle NOTAMs often carry a more precise E-text
            // coordinate than the Q-line centre. Prefer that explicit point.
            if (($semantic['semantic_class'] ?? '') === 'OBSTACLE') {
                $textCoords = extractNotamCoordinates((string)($row['notam_text'] ?? ''), true);
                if ($textCoords) {
                    $geometry = [
                        'type' => 'Point',
                        'coordinates' => [(float)$textCoords[0][0], (float)$textCoords[0][1]],
                    ];
                    $geometrySource = 'e-text-point';
                    $geometryType = 'Point';
                }
            }

            if (!notamPointIsRenderable($row, $geometrySource, $semantic)) {
                return null;
            }
        }
    }

    $mapRenderType = match ($geometryType) {
        'Polygon', 'MultiPolygon' => $explicit['render_type'] ?? 'AREA',
        'LineString', 'MultiLineString' => 'LINE',
        'Point', 'MultiPoint' => $geometrySource === 'airport-location' ? 'ENTITY' : 'POINT',
        default => 'NONE',
    };

    $geometryAccuracy = match ($geometrySource) {
        'faa-geometry' => 'authoritative FAA geometry',
        'e-text-polygon' => 'explicit NOTAM boundary',
        'e-text-circle' => 'explicit NOTAM circle',
        'e-text-corridor' => 'explicit NOTAM corridor',
        'e-text-point' => 'explicit NOTAM point',
        'qline-coordinate' => 'coordinate point fallback',
        'airport-location' => 'airport entity location',
        default => 'derived',
    };

    $category = notamCategory($row, $semantic);

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
            'qline_radius_nm' => $qlineRadiusNm,
            'explicit_radius_nm' => $explicit['explicit_radius_nm'] ?? null,
            'selection_code' => $row['selection_code'] ?? null,
            'q_subject' => $semantic['q_subject'],
            'semantic_class' => $semantic['semantic_class'],
            'display_group' => $semantic['display_group'],
            'category' => $category,
            'map_render_type' => $mapRenderType,
            'geometry_source' => $geometrySource,
            'geometry_accuracy' => $geometryAccuracy,
        ],
    ];
}

function notamFeatureFingerprint(array $feature): string {
    $p = $feature['properties'] ?? [];
    return hash('sha256', json_encode([
        $p['ident'] ?? '',
        $p['effective_start'] ?? '',
        $p['effective_end_raw'] ?? ($p['effective_end'] ?? ''),
        $p['text'] ?? '',
        $feature['geometry'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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


if ($action === 'notam-detail') {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '' || strlen($id) > 160) {
        respond(400, ['ok' => false, 'error' => 'Geçersiz NOTAM id.']);
    }

    $stmt = $pdo->prepare(
        "SELECT
            nms_id, series, number, year, notam_type, classification,
            location, icao_location, selection_code, traffic, purpose, scope,
            effective_start, effective_end, effective_end_raw,
            lower_limit, upper_limit, radius_nm, notam_text, status, last_updated
         FROM notams
         WHERE source = 'FAA_NMS'
           AND environment = 'production'
           AND nms_id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) respond(404, ['ok' => false, 'error' => 'NOTAM bulunamadı.']);

    respond(200, ['ok' => true, 'notam' => $row]);
}

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
$notamCacheFile = null;

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
$notamPerf = null;
$notamPerfStart = null;
if (in_array('notam', $layers, true) && $zoom >= 4) {
    $notamPerf = [];
    $notamPerfStart = microtime(true);
    $atRaw = trim((string)($_GET['at'] ?? ''));
    try {
        $at = $atRaw !== ''
            ? new DateTimeImmutable($atRaw, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable) {
        respond(400, ['ok' => false, 'error' => 'Geçersiz NOTAM zamanı. UTC ISO tarih/saat gönder.']);
    }
    $atSql = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    if (count($layers) === 1 && $layers[0] === 'notam') {
        $cacheVersion = navmapNotamSyncVersion($pdo, 'production');
        $cacheKey = hash('sha256', json_encode([
            'v10',
            $cacheVersion,
            $zoom,
            round($west, 5),
            round($south, 5),
            round($east, 5),
            round($north, 5),
            $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i'),
        ], JSON_UNESCAPED_SLASHES));

        $notamCacheFile = navmapCacheDir() . DIRECTORY_SEPARATOR . 'notam_' . $cacheKey . '.json';
        $cacheReadStart = microtime(true);
        $cachedPayload = navmapCacheRead($notamCacheFile, 900);
        $cacheReadMs = (microtime(true) - $cacheReadStart) * 1000.0;
        if ($cachedPayload !== null) {
            $totalMs = (microtime(true) - $notamPerfStart) * 1000.0;
            header('X-YC-NavMap-Cache: HIT');
            header('Server-Timing: notam-cache;dur=' . round($cacheReadMs, 1) . ', notam-total;dur=' . round($totalMs, 1));
            header('X-YC-NavMap-Timing: cache=' . round($cacheReadMs, 1) . 'ms,total=' . round($totalMs, 1) . 'ms');
            respond(200, $cachedPayload);
        }

        header('X-YC-NavMap-Cache: MISS');
    }

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
                n.location, n.icao_location, n.radius_nm, n.selection_code, n.traffic, n.purpose, n.scope, n.coordinates_raw,
                n.effective_start, n.effective_end,
                n.effective_end_raw, n.lower_limit, n.upper_limit,
                ST_AsGeoJSON(n.geometry, 6) AS geometry,
                \'faa-geometry\' AS geometry_source
            FROM notams n
            WHERE ' . $baseTimeWhere . '
              AND n.geometry IS NOT NULL
              AND ' . $bboxExpr . '
            ORDER BY n.effective_start DESC
            LIMIT 5000';

    $faaSqlStart = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $faaRows = $stmt->fetchAll();
    $notamPerf['faa_sql'] = (microtime(true) - $faaSqlStart) * 1000.0;

    $notamCount = 0;
    $seenNotams = [];
    $seenMapFeatures = [];

    $faaTextStart = microtime(true);
    $rows = hydrateNotamTexts($pdo, $faaRows);
    $notamPerf['faa_text'] = (microtime(true) - $faaTextStart) * 1000.0;

    $faaBuildStart = microtime(true);
    foreach ($rows as $row) {
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[(string)$row['nms_id']] = true;
        $fingerprint = notamFeatureFingerprint($feature);
        if (isset($seenMapFeatures[$fingerprint])) continue;
        $seenMapFeatures[$fingerprint] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }
    $notamPerf['faa_build'] = (microtime(true) - $faaBuildStart) * 1000.0;

    // 2) Many Initial Load AIXM records have no GeoJSON geometry. Resolve the
    // small set of airports visible in the viewport first, then query NOTAMs
    // against their indexed location columns. This deliberately avoids the
    // previous UPPER(...)=UPPER(...) OR join, which forced MariaDB into a very
    // expensive join plan on the full NOTAM set.
    $airportWhereLon = $west <= $east
        ? 'lon BETWEEN :west AND :east'
        : '(lon >= :west OR lon <= :east)';

    $airportPointStart = microtime(true);
    $airportPointStmt = $pdo->prepare(
        "SELECT ident, lat, lon
         FROM nav_points
         WHERE kind = 'airport'
           AND lat BETWEEN :south AND :north
           AND " . $airportWhereLon
    );
    $airportPointStmt->execute([
        'south' => $south,
        'north' => $north,
        'west' => $west,
        'east' => $east,
    ]);

    $airportByIdent = [];
    while ($airport = $airportPointStmt->fetch()) {
        $ident = strtoupper(trim((string)($airport['ident'] ?? '')));
        if ($ident === '') continue;
        $airportByIdent[$ident] = [
            'lat' => (float)$airport['lat'],
            'lon' => (float)$airport['lon'],
        ];
    }
    $notamPerf['airport_points_sql'] = (microtime(true) - $airportPointStart) * 1000.0;

    $airportSqlStart = microtime(true);
    $airportRowsById = [];

    if ($airportByIdent) {
        $airportIdents = array_keys($airportByIdent);

        foreach (['icao_location', 'location'] as $airportColumn) {
            $params = [
                'at_start' => $atSql,
                'at_end' => $atSql,
                'environment' => 'production',
            ];
            $placeholders = [];

            foreach ($airportIdents as $i => $ident) {
                $key = 'airport_' . $airportColumn . '_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $ident;
            }

            $airportNotamSql = 'SELECT
                    n.nms_id, n.series, n.number, n.year, n.classification,
                    n.location, n.icao_location, n.radius_nm, n.selection_code, n.traffic, n.purpose, n.scope, n.coordinates_raw,
                    n.effective_start, n.effective_end,
                    n.effective_end_raw, n.lower_limit, n.upper_limit
                FROM notams n
                WHERE ' . $baseTimeWhere . '
                  AND n.geometry IS NULL
                  AND n.' . $airportColumn . ' IN (' . implode(',', $placeholders) . ')
                ORDER BY n.effective_start DESC
                LIMIT 5000';

            $airportNotamStmt = $pdo->prepare($airportNotamSql);
            $airportNotamStmt->execute($params);

            while ($row = $airportNotamStmt->fetch()) {
                $id = (string)$row['nms_id'];
                if ($id === '' || isset($airportRowsById[$id])) continue;

                $airportIdent = strtoupper(trim((string)($row[$airportColumn] ?? '')));
                $point = $airportByIdent[$airportIdent] ?? null;
                if ($point === null) continue;

                $row['geometry'] = json_encode([
                    'type' => 'Point',
                    'coordinates' => [$point['lon'], $point['lat']],
                ], JSON_UNESCAPED_SLASHES);
                $row['geometry_source'] = 'airport-location';
                $airportRowsById[$id] = $row;
            }
        }
    }

    $notamPerf['airport_sql'] = (microtime(true) - $airportSqlStart) * 1000.0;

    $airportTextStart = microtime(true);
    $fallbackRows = hydrateNotamTexts($pdo, array_values($airportRowsById));
    $notamPerf['airport_text'] = (microtime(true) - $airportTextStart) * 1000.0;

    $airportBuildStart = microtime(true);
    foreach ($fallbackRows as $row) {
        $id = (string)$row['nms_id'];
        if (isset($seenNotams[$id])) continue;
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[$id] = true;
        $fingerprint = notamFeatureFingerprint($feature);
        if (isset($seenMapFeatures[$fingerprint])) continue;
        $seenMapFeatures[$fingerprint] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }
    $notamPerf['airport_build'] = (microtime(true) - $airportBuildStart) * 1000.0;

    // 3) If neither FAA GeoJSON nor airport location is available, derive a
    // point from the standard Q-line coordinate token in coordinates_raw.
    // Long E-text is fetched only after the lightweight spatial candidate pass.
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
        UPPER(COALESCE(n.coordinates_raw, '')),
        '([0-9]{6}[NS][0-9]{7}[EW]|[0-9]{4}[NS][0-9]{5}[EW])'
    )";

    $coordSql = 'SELECT
            q.nms_id, q.series, q.number, q.year, q.classification,
            q.location, q.icao_location, q.radius_nm, q.selection_code, q.traffic, q.purpose, q.scope, q.coordinates_raw,
            q.effective_start, q.effective_end,
            q.effective_end_raw, q.lower_limit, q.upper_limit,
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
                    n.location, n.icao_location, n.radius_nm, n.selection_code, n.traffic, n.purpose, n.scope, n.coordinates_raw,
                    n.effective_start, n.effective_end,
                    n.effective_end_raw, n.lower_limit, n.upper_limit,
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

    $coordSqlStart = microtime(true);
    $coordStmt = $pdo->prepare($coordSql);
    $coordStmt->execute($coordParams);
    $coordRowsRaw = $coordStmt->fetchAll();
    $notamPerf['coord_sql'] = (microtime(true) - $coordSqlStart) * 1000.0;

    $coordTextStart = microtime(true);
    $coordRows = hydrateNotamTexts($pdo, $coordRowsRaw);
    $notamPerf['coord_text'] = (microtime(true) - $coordTextStart) * 1000.0;

    $coordBuildStart = microtime(true);
    foreach ($coordRows as $row) {
        $id = (string)$row['nms_id'];
        if (isset($seenNotams[$id])) continue;
        $feature = notamFeature($row);
        if (!$feature) continue;
        $seenNotams[$id] = true;
        $fingerprint = notamFeatureFingerprint($feature);
        if (isset($seenMapFeatures[$fingerprint])) continue;
        $seenMapFeatures[$fingerprint] = true;
        $features[] = $feature;
        $counts['notam']++;
        $notamCount++;
    }
    $notamPerf['coord_build'] = (microtime(true) - $coordBuildStart) * 1000.0;

    if ($notamCount >= 5000) $truncated = true;
}

$payload = [
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
];

if (is_string($notamCacheFile) && $notamCacheFile !== '') {
    navmapCacheWrite($notamCacheFile, $payload);
}

if (is_array($notamPerf) && $notamPerfStart !== null) {
    $notamPerf['total'] = (microtime(true) - $notamPerfStart) * 1000.0;

    $serverTiming = [];
    $compactTiming = [];
    foreach ($notamPerf as $name => $durationMs) {
        $duration = round((float)$durationMs, 1);
        $safeName = preg_replace('/[^a-z0-9_-]+/i', '-', (string)$name);
        $serverTiming[] = 'notam-' . $safeName . ';dur=' . $duration;
        $compactTiming[] = $name . '=' . $duration . 'ms';
    }

    header('Server-Timing: ' . implode(', ', $serverTiming));
    header('X-YC-NavMap-Timing: ' . implode(',', $compactTiming));
}

respond(200, $payload);
