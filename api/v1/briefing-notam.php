<?php
declare(strict_types=1);

/**
 * Transparent Pilot Briefing NOTAM enrichment layer.
 *
 * The public URL remains /main/api/v1/briefing.php. Apache/LiteSpeed rewrites
 * that request here, this file runs the existing deterministic briefing engine,
 * then enriches its JSON with route/time/level-aware FAA NMS NOTAM relevance.
 *
 * Important: this does not validate or approve a flight plan. It only adds
 * deterministic NOTAM context to the existing estimated briefing result.
 */

require_once dirname(__DIR__, 2) . '/notam/core.php';

function ycBriefingNotamUtc(?string $raw, ?DateTimeImmutable $fallback = null): DateTimeImmutable {
    $utc = new DateTimeZone('UTC');
    $raw = trim((string)$raw);
    if ($raw === '') return $fallback ?? new DateTimeImmutable('now', $utc);
    try {
        return (new DateTimeImmutable($raw, $utc))->setTimezone($utc);
    } catch (Throwable) {
        return $fallback ?? new DateTimeImmutable('now', $utc);
    }
}

function ycBriefingNotamHaversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 3440.065;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))));
}

function ycBriefingNotamPointSegmentNm(float $lat, float $lon, float $lat1, float $lon1, float $lat2, float $lon2): float {
    $lat0 = deg2rad(($lat + $lat1 + $lat2) / 3.0);
    $x = ($lon - $lon1) * cos($lat0) * 60.0;
    $y = ($lat - $lat1) * 60.0;
    $dx = ($lon2 - $lon1) * cos($lat0) * 60.0;
    $dy = ($lat2 - $lat1) * 60.0;
    $den = $dx * $dx + $dy * $dy;
    if ($den < 1e-12) return sqrt($x * $x + $y * $y);
    $t = max(0.0, min(1.0, ($x * $dx + $y * $dy) / $den));
    $px = $t * $dx; $py = $t * $dy;
    return sqrt(($x - $px) ** 2 + ($y - $py) ** 2);
}

function ycBriefingNotamOrient(float $ax, float $ay, float $bx, float $by, float $cx, float $cy): float {
    return ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
}

function ycBriefingNotamSegmentsIntersect(array $a, array $b, array $c, array $d): bool {
    $o1 = ycBriefingNotamOrient((float)$a[1], (float)$a[0], (float)$b[1], (float)$b[0], (float)$c[1], (float)$c[0]);
    $o2 = ycBriefingNotamOrient((float)$a[1], (float)$a[0], (float)$b[1], (float)$b[0], (float)$d[1], (float)$d[0]);
    $o3 = ycBriefingNotamOrient((float)$c[1], (float)$c[0], (float)$d[1], (float)$d[0], (float)$a[1], (float)$a[0]);
    $o4 = ycBriefingNotamOrient((float)$c[1], (float)$c[0], (float)$d[1], (float)$d[0], (float)$b[1], (float)$b[0]);
    return (($o1 > 0 && $o2 < 0) || ($o1 < 0 && $o2 > 0))
        && (($o3 > 0 && $o4 < 0) || ($o3 < 0 && $o4 > 0));
}

function ycBriefingNotamPointInRing(float $lat, float $lon, array $ring): bool {
    $inside = false; $n = count($ring);
    if ($n < 3) return false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)($ring[$i][0] ?? 0.0); $yi = (float)($ring[$i][1] ?? 0.0);
        $xj = (float)($ring[$j][0] ?? 0.0); $yj = (float)($ring[$j][1] ?? 0.0);
        $crosses = (($yi > $lat) !== ($yj > $lat));
        if (!$crosses) continue;
        $x = ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi;
        if ($lon < $x) $inside = !$inside;
    }
    return $inside;
}

function ycBriefingNotamLineDistance(array $route, array $line): float {
    if (count($route) < 2 || count($line) < 1) return INF;
    $best = INF;

    if (count($line) === 1 && is_array($line[0]) && count($line[0]) >= 2) {
        $lon = (float)$line[0][0]; $lat = (float)$line[0][1];
        for ($i = 0; $i < count($route) - 1; $i++) {
            $a = $route[$i]; $b = $route[$i + 1];
            $best = min($best, ycBriefingNotamPointSegmentNm($lat, $lon, (float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]));
        }
        return $best;
    }

    for ($i = 0; $i < count($route) - 1; $i++) {
        $a = $route[$i]; $b = $route[$i + 1];
        for ($j = 0; $j < count($line) - 1; $j++) {
            $p = $line[$j] ?? null; $q = $line[$j + 1] ?? null;
            if (!is_array($p) || !is_array($q) || count($p) < 2 || count($q) < 2) continue;
            $c = [(float)$p[1], (float)$p[0]];
            $d = [(float)$q[1], (float)$q[0]];
            if (ycBriefingNotamSegmentsIntersect($a, $b, $c, $d)) return 0.0;

            $best = min(
                $best,
                ycBriefingNotamPointSegmentNm((float)$c[0], (float)$c[1], (float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]),
                ycBriefingNotamPointSegmentNm((float)$d[0], (float)$d[1], (float)$a[0], (float)$a[1], (float)$b[0], (float)$b[1]),
                ycBriefingNotamPointSegmentNm((float)$a[0], (float)$a[1], (float)$c[0], (float)$c[1], (float)$d[0], (float)$d[1]),
                ycBriefingNotamPointSegmentNm((float)$b[0], (float)$b[1], (float)$c[0], (float)$c[1], (float)$d[0], (float)$d[1])
            );
        }
    }
    return $best;
}

function ycBriefingNotamPolygonDistance(array $route, array $rings): float {
    $outer = $rings[0] ?? null;
    if (!is_array($outer) || count($outer) < 3) return INF;
    foreach ($route as $p) {
        if (is_array($p) && count($p) >= 2 && ycBriefingNotamPointInRing((float)$p[0], (float)$p[1], $outer)) return 0.0;
    }
    return ycBriefingNotamLineDistance($route, $outer);
}

function ycBriefingNotamGeometryDistance(array $route, ?array $geometry): float {
    if (!$geometry || count($route) < 2) return INF;
    $type = (string)($geometry['type'] ?? '');
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords)) return INF;

    if ($type === 'Point') return ycBriefingNotamLineDistance($route, [$coords]);
    if ($type === 'MultiPoint' || $type === 'LineString') return ycBriefingNotamLineDistance($route, $coords);
    if ($type === 'Polygon') return ycBriefingNotamPolygonDistance($route, $coords);

    $best = INF;
    if ($type === 'MultiLineString') {
        foreach ($coords as $line) if (is_array($line)) $best = min($best, ycBriefingNotamLineDistance($route, $line));
    } elseif ($type === 'MultiPolygon') {
        foreach ($coords as $poly) if (is_array($poly)) $best = min($best, ycBriefingNotamPolygonDistance($route, $poly));
    }
    return $best;
}

function ycBriefingNotamSemantic(?string $selectionCode): string {
    $q = strtoupper(trim((string)$selectionCode));
    $subject = preg_match('/^Q([A-Z]{2})[A-Z]{2}$/', $q, $m) ? $m[1] : '';
    return match ($subject) {
        'MR' => 'RUNWAY',
        'MX' => 'TAXIWAY',
        'MN' => 'APRON',
        'FA', 'AF', 'AC' => 'AIRSPACE',
        'RD', 'RP', 'RR', 'RT' => 'RESTRICTED_AIRSPACE',
        'WY' => 'AERIAL_SURVEY',
        'WE', 'WF', 'WM' => 'MILITARY_ACTIVITY',
        'WU' => 'UAV_ACTIVITY',
        'OB' => 'OBSTACLE',
        default => 'OTHER',
    };
}

function ycBriefingNotamRouteRefs(array $payload, array $query): array {
    $refs = [];
    $add = static function(mixed $value) use (&$refs): void {
        $v = strtoupper(trim((string)$value));
        if ($v !== '' && preg_match('/^[A-Z0-9]{2,12}$/', $v)) $refs[$v] = true;
    };

    foreach (($payload['routeEngine']['navdataResolved'] ?? []) as $row) {
        if (is_array($row)) $add($row['id'] ?? null);
    }
    $structure = $payload['routeInput']['structure'] ?? null;
    if (is_array($structure)) {
        $add($structure['departure']['sid'] ?? null);
        $add($structure['arrival']['star'] ?? null);
        foreach (($structure['enroute'] ?? []) as $row) {
            if (is_array($row) && in_array(($row['type'] ?? ''), ['airway', 'procedure'], true)) $add($row['id'] ?? null);
        }
    }

    $raw = strtoupper((string)($query['route'] ?? ''));
    if ($raw !== '') {
        preg_match_all('/\b(?:[UKS]?[ABGRLMNPHJVWQTYZ]\d{1,4}[A-Z]?|[A-Z]{3,6}\d[A-Z])\b/', $raw, $matches);
        foreach (($matches[0] ?? []) as $token) $add($token);
    }
    return array_slice(array_keys($refs), 0, 16);
}

function ycBriefingNotamBbox(array $route, float $paddingDeg = 1.5): array {
    $lats = []; $lons = [];
    foreach ($route as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $lats[] = (float)$p[0]; $lons[] = (float)$p[1];
    }
    if (!$lats || !$lons) return [-180.0, -90.0, 180.0, 90.0];
    $south = max(-90.0, min($lats) - $paddingDeg);
    $north = min(90.0, max($lats) + $paddingDeg);
    $west = min($lons) - $paddingDeg;
    $east = max($lons) + $paddingDeg;
    if (($east - $west) > 180.0) return [-180.0, $south, 180.0, $north];
    return [max(-180.0, $west), $south, min(180.0, $east), $north];
}

function ycBriefingNotamVertical(array $row, int $cruiseFl): array {
    $min = isset($row['minimum_fl']) && $row['minimum_fl'] !== null ? (int)$row['minimum_fl'] : null;
    $max = isset($row['maximum_fl']) && $row['maximum_fl'] !== null ? (int)$row['maximum_fl'] : null;
    if ($min === null && $max === null) return ['relation' => 'unknown', 'overlap' => true];
    if ($min !== null && $cruiseFl < $min) return ['relation' => 'below_notam', 'overlap' => false];
    if ($max !== null && $cruiseFl > $max) return ['relation' => 'above_notam', 'overlap' => false];
    return ['relation' => 'at_cruise_level', 'overlap' => true];
}

function ycBriefingNotamIdentifier(array $row): string {
    return ycNotamIdent($row) ?: (string)($row['nms_id'] ?? 'NOTAM');
}

function ycBriefingNotamEnrich(array $payload, array $query): array {
    $route = array_values(array_filter(
        $payload['route'] ?? [],
        static fn($p): bool => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])
    ));
    if (count($route) < 2) return $payload;

    $from = strtoupper(trim((string)($query['from'] ?? ($payload['routeInput']['structure']['departure']['airport'] ?? ''))));
    $to = strtoupper(trim((string)($query['to'] ?? ($payload['routeInput']['structure']['arrival']['airport'] ?? ''))));
    $flight = is_array($payload['flight'] ?? null) ? $payload['flight'] : [];
    $cruiseFl = max(0, min(600, (int)($flight['cruiseFL'] ?? ($query['fl'] ?? 0))));
    $etd = ycBriefingNotamUtc($flight['etdUtc'] ?? ($query['etd'] ?? null));
    $etaFallback = $etd->modify('+12 hours');
    $eta = ycBriefingNotamUtc($flight['estimatedArrivalUtc'] ?? null, $etaFallback);
    if ($eta < $etd) $eta = $etaFallback;

    [$west, $south, $east, $north] = ycBriefingNotamBbox($route);
    $bboxWkt = sprintf(
        'POLYGON((%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F,%.6F %.6F))',
        $west, $south, $east, $south, $east, $north, $west, $north, $west, $south
    );
    $refs = ycBriefingNotamRouteRefs($payload, $query);
    $regex = '';
    if ($refs) {
        $escaped = array_map(static fn(string $v): string => preg_quote($v, '/'), $refs);
        $regex = '(^|[^A-Z0-9])(' . implode('|', $escaped) . ')([^A-Z0-9]|$)';
    }

    $pdo = nmsDb();
    $candidateParts = [
        "(n.geometry IS NOT NULL AND MBRIntersects(n.geometry, ST_GeomFromText(:bbox_wkt)))",
        "UPPER(COALESCE(n.icao_location, '')) IN (:dep_icao, :arr_icao)",
        "UPPER(COALESCE(n.location, '')) IN (:dep_loc, :arr_loc)",
    ];
    $params = [
        'source' => YC_NOTAM_SOURCE,
        'environment' => YC_NOTAM_ENVIRONMENT,
        'window_start' => $etd->format('Y-m-d H:i:s'),
        'window_end' => $eta->format('Y-m-d H:i:s'),
        'bbox_wkt' => $bboxWkt,
        'dep_icao' => $from,
        'arr_icao' => $to,
        'dep_loc' => $from,
        'arr_loc' => $to,
    ];
    if ($regex !== '') {
        $candidateParts[] = "UPPER(COALESCE(n.notam_text, '')) REGEXP :route_regex";
        $params['route_regex'] = $regex;
    }

    $sql = "SELECT
                n.nms_id,n.series,n.number,n.year,n.notam_type,n.classification,
                n.affected_fir,n.location,n.icao_location,n.selection_code,n.traffic,n.purpose,n.scope,
                n.minimum_fl,n.maximum_fl,n.effective_start,n.effective_end,n.effective_end_raw,
                n.schedule,n.lower_limit,n.upper_limit,n.radius_nm,n.status,n.last_updated,n.notam_text,
                ST_AsGeoJSON(n.geometry,6) AS geometry_json
            FROM notams n
            WHERE n.source=:source
              AND n.environment=:environment
              AND n.status <> 'cancelled'
              AND (n.effective_start IS NULL OR n.effective_start <= :window_end)
              AND (
                    UPPER(COALESCE(n.effective_end_raw,''))='PERM'
                    OR n.effective_end IS NULL
                    OR n.effective_end >= :window_start
              )
              AND (" . implode(' OR ', $candidateParts) . ")
            ORDER BY n.last_updated DESC
            LIMIT 600";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $geometry = json_decode((string)($row['geometry_json'] ?? ''), true);
        $geometry = is_array($geometry) ? $geometry : null;
        $distance = ycBriefingNotamGeometryDistance($route, $geometry);
        $distanceNm = is_finite($distance) ? round($distance, 1) : null;

        $loc = strtoupper(trim((string)($row['icao_location'] ?? $row['location'] ?? '')));
        $endpoint = $loc !== '' && ($loc === $from || $loc === $to);
        $text = strtoupper((string)($row['notam_text'] ?? ''));
        $matchedRefs = [];
        foreach ($refs as $ref) {
            if (preg_match('/(^|[^A-Z0-9])' . preg_quote($ref, '/') . '([^A-Z0-9]|$)/', $text)) $matchedRefs[] = $ref;
        }

        $vertical = ycBriefingNotamVertical($row, $cruiseFl);
        $intersects = $distanceNm !== null && $distanceNm <= 5.0;
        $near = $distanceNm !== null && $distanceNm <= 50.0;
        $referenceMatch = !empty($matchedRefs);
        $relevant = $endpoint || $referenceMatch || ($near && $vertical['overlap']);
        if (!$relevant) continue;

        $basis = $endpoint ? 'endpoint' : ($referenceMatch ? 'route_reference' : ($intersects ? 'route_intersection' : 'near_route'));
        $semantic = ycBriefingNotamSemantic($row['selection_code'] ?? null);
        $score = $endpoint ? 0.0 : ($referenceMatch ? 8.0 : (20.0 + (float)($distanceNm ?? 100.0)));
        if (!$vertical['overlap'] && !$endpoint) $score += 35.0;

        $items[] = [
            '_score' => $score,
            'id' => (string)($row['nms_id'] ?? ''),
            'ident' => ycBriefingNotamIdentifier($row),
            'location' => $row['icao_location'] ?: ($row['location'] ?? null),
            'fir' => $row['affected_fir'] ?? null,
            'semantic' => $semantic,
            'basis' => $basis,
            'distanceNm' => $distanceNm,
            'matchedRouteRefs' => $matchedRefs,
            'verticalRelation' => $vertical['relation'],
            'cruiseLevelOverlap' => (bool)$vertical['overlap'],
            'minimumFl' => isset($row['minimum_fl']) && $row['minimum_fl'] !== null ? (int)$row['minimum_fl'] : null,
            'maximumFl' => isset($row['maximum_fl']) && $row['maximum_fl'] !== null ? (int)$row['maximum_fl'] : null,
            'effectiveStart' => $row['effective_start'] ?? null,
            'effectiveEnd' => $row['effective_end'] ?? null,
            'effectiveEndRaw' => $row['effective_end_raw'] ?? null,
            'schedule' => $row['schedule'] ?? null,
            'text' => $row['notam_text'] ?? null,
        ];
    }

    usort($items, static fn(array $a, array $b): int => ($a['_score'] <=> $b['_score']) ?: strcmp((string)$a['ident'], (string)$b['ident']));
    $items = array_slice($items, 0, 60);
    foreach ($items as &$item) unset($item['_score']);
    unset($item);

    $endpointCount = count(array_filter($items, static fn(array $i): bool => $i['basis'] === 'endpoint'));
    $intersectionCount = count(array_filter($items, static fn(array $i): bool => $i['basis'] === 'route_intersection'));
    $nearCount = count(array_filter($items, static fn(array $i): bool => $i['basis'] === 'near_route'));
    $referenceCount = count(array_filter($items, static fn(array $i): bool => $i['basis'] === 'route_reference'));
    $cruiseCount = count(array_filter($items, static fn(array $i): bool => !empty($i['cruiseLevelOverlap'])));

    $impact = [
        'available' => true,
        'source' => 'FAA NMS local MariaDB',
        'checkedAt' => gmdate('c'),
        'flightWindow' => ['from' => $etd->format(DATE_ATOM), 'to' => $eta->format(DATE_ATOM)],
        'cruiseFL' => $cruiseFl,
        'routeCorridorNm' => 50,
        'scheduleEvaluated' => false,
        'routeReferences' => $refs,
        'candidateCount' => count($rows),
        'relevantCount' => count($items),
        'endpointCount' => $endpointCount,
        'routeIntersectionCount' => $intersectionCount,
        'nearRouteCount' => $nearCount,
        'referenceMatchCount' => $referenceCount,
        'atCruiseLevelCount' => $cruiseCount,
        'items' => $items,
    ];
    $payload['notamImpact'] = $impact;

    if (!isset($payload['sourceStatus']) || !is_array($payload['sourceStatus'])) $payload['sourceStatus'] = [];
    $payload['sourceStatus']['notam'] = [
        'ok' => true,
        'source' => 'FAA NMS local MariaDB',
        'candidateCount' => count($rows),
        'relevantCount' => count($items),
        'scheduleEvaluated' => false,
    ];

    if (!isset($payload['routeEngine']) || !is_array($payload['routeEngine'])) $payload['routeEngine'] = [];
    $warnings = is_array($payload['routeEngine']['warnings'] ?? null) ? $payload['routeEngine']['warnings'] : [];
    if ($items) {
        $warnings[] = sprintf(
            'NOTAM check: %d potentially relevant NOTAM(s) overlap the flight window; schedule text is not automatically evaluated.',
            count($items)
        );
        foreach (array_slice($items, 0, 3) as $item) {
            $where = $item['location'] ?: ($item['fir'] ?: 'route');
            $detail = strtoupper((string)$item['basis']);
            if ($item['distanceNm'] !== null && !in_array($item['basis'], ['endpoint', 'route_reference'], true)) {
                $detail .= ' ' . $item['distanceNm'] . 'NM';
            }
            $warnings[] = sprintf('NOTAM %s · %s · %s · %s', $item['ident'], $where, $item['semantic'], $detail);
        }
    }
    $payload['routeEngine']['warnings'] = array_values(array_unique(array_filter(array_map('strval', $warnings))));

    return $payload;
}

function ycBriefingNotamFailure(array $payload): array {
    $payload['notamImpact'] = [
        'available' => false,
        'source' => 'FAA NMS local MariaDB',
        'checkedAt' => gmdate('c'),
        'error' => 'NOTAM relevance check unavailable.',
    ];
    if (!isset($payload['sourceStatus']) || !is_array($payload['sourceStatus'])) $payload['sourceStatus'] = [];
    $payload['sourceStatus']['notam'] = ['ok' => false, 'error' => 'NOTAM relevance check unavailable.'];
    if (!isset($payload['routeEngine']) || !is_array($payload['routeEngine'])) $payload['routeEngine'] = [];
    $warnings = is_array($payload['routeEngine']['warnings'] ?? null) ? $payload['routeEngine']['warnings'] : [];
    $warnings[] = 'NOTAM relevance check unavailable.';
    $payload['routeEngine']['warnings'] = array_values(array_unique($warnings));
    return $payload;
}

ob_start();
register_shutdown_function(static function (): void {
    $body = '';
    if (ob_get_level() > 0) {
        $body = (string)ob_get_contents();
        @ob_end_clean();
    }
    if ($body === '') return;

    $status = http_response_code();
    $payload = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($payload) || !($payload['ok'] ?? false)) {
        echo $body;
        return;
    }

    try {
        $payload = ycBriefingNotamEnrich($payload, $_GET);
    } catch (Throwable $e) {
        error_log('[briefing-notam] ' . $e->getMessage());
        $payload = ycBriefingNotamFailure($payload);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

require __DIR__ . '/briefing.php';
