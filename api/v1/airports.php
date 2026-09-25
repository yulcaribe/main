<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/notam/nms/internal/store.php';

ycApiV1Headers('public, max-age=120, stale-while-revalidate=300');
ycApiV1Method('GET');

$action = strtolower(trim((string)($_GET['action'] ?? 'search')));

try {
    $pdo = nmsDb();

    if ($action === 'detail') {
        $ident = strtoupper(ycApiV1String($_GET, 'ident', 8));
        if (!preg_match('/^[A-Z0-9]{3,8}$/', $ident)) {
            ycApiV1Respond(400, ['ok' => false, 'error' => 'Geçerli airport ident gerekli.']);
        }

        $stmt = $pdo->prepare(
            "SELECT id, ident, iata, name, city, lat, lon, elevation_ft, type_code, provider_status
             FROM nav_points
             WHERE kind = 'airport'
               AND (UPPER(ident) = :ident OR UPPER(COALESCE(iata, '')) = :iata)
             ORDER BY (UPPER(ident) = :ident_order) DESC
             LIMIT 1"
        );
        $stmt->execute([
            'ident' => $ident,
            'iata' => $ident,
            'ident_order' => $ident,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) ycApiV1Respond(404, ['ok' => false, 'error' => 'Airport bulunamadı.']);

        ycApiV1Respond(200, [
            'ok' => true,
            'resource' => 'airports',
            'mode' => 'detail',
            'airport' => [
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
            ],
        ]);
    }

    if ($action === 'near') {
        $lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
        $lon = filter_input(INPUT_GET, 'lon', FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lat === null || $lon === false || $lon === null) {
            ycApiV1Respond(400, ['ok' => false, 'error' => 'lat ve lon gerekli.']);
        }

        $delta = isset($_GET['delta']) && is_numeric($_GET['delta']) ? (float)$_GET['delta'] : 1.0;
        $delta = max(0.05, min(10.0, $delta));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));

        $stmt = $pdo->prepare(
            "SELECT id, ident, iata, name, city, lat, lon, elevation_ft, type_code, provider_status
             FROM nav_points
             WHERE kind = 'airport'
               AND lat BETWEEN :min_lat AND :max_lat
               AND lon BETWEEN :min_lon AND :max_lon
             ORDER BY ABS(lat - :lat_order) + ABS(lon - :lon_order)
             LIMIT {$limit}"
        );
        $stmt->execute([
            'min_lat' => (float)$lat - $delta,
            'max_lat' => (float)$lat + $delta,
            'min_lon' => (float)$lon - $delta,
            'max_lon' => (float)$lon + $delta,
            'lat_order' => (float)$lat,
            'lon_order' => (float)$lon,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ycApiV1Respond(200, [
            'ok' => true,
            'resource' => 'airports',
            'mode' => 'near',
            'center' => ['lat' => (float)$lat, 'lon' => (float)$lon],
            'delta' => $delta,
            'count' => count($rows),
            'items' => array_map(static fn(array $row): array => [
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
            ], $rows),
        ]);
    }

    if ($action !== 'search') {
        ycApiV1Respond(400, ['ok' => false, 'error' => 'Geçersiz action.']);
    }

    $q = strtoupper(ycApiV1String($_GET, 'q', 80));
    if (strlen($q) < 2) {
        ycApiV1Respond(400, ['ok' => false, 'error' => 'En az 2 karakter gir.']);
    }

    $like = '%' . $q . '%';
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

    $stmt = $pdo->prepare(
        "SELECT id, ident, iata, name, city, lat, lon, elevation_ft, type_code, provider_status
         FROM nav_points
         WHERE kind = 'airport'
           AND (
                UPPER(ident) LIKE :ident
                OR UPPER(COALESCE(iata, '')) LIKE :iata
                OR UPPER(COALESCE(name, '')) LIKE :name
                OR UPPER(COALESCE(city, '')) LIKE :city
           )
         ORDER BY
            (UPPER(ident) = :exact_icao) DESC,
            (UPPER(COALESCE(iata, '')) = :exact_iata) DESC,
            ident
         LIMIT {$limit}"
    );
    $stmt->execute([
        'ident' => $like,
        'iata' => $like,
        'name' => $like,
        'city' => $like,
        'exact_icao' => $q,
        'exact_iata' => $q,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ycApiV1Respond(200, [
        'ok' => true,
        'resource' => 'airports',
        'mode' => 'search',
        'query' => $q,
        'count' => count($rows),
        'items' => array_map(static fn(array $row): array => [
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
        ], $rows),
    ]);
} catch (Throwable $e) {
    ycApiV1Respond(500, ['ok' => false, 'error' => 'Airport API isteği işlenemedi.']);
}
