<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/nms_store.php';

function out(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$icao = strtoupper(trim((string)($_GET['icao'] ?? '')));
if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) {
    out(400, ['ok' => false, 'error' => 'Geçerli 4 karakterli ICAO kodu gerekli.']);
}

$atRaw = trim((string)($_GET['at'] ?? ''));
try {
    $at = $atRaw !== ''
        ? new DateTimeImmutable($atRaw, new DateTimeZone('UTC'))
        : new DateTimeImmutable('now', new DateTimeZone('UTC'));
} catch (Throwable) {
    out(400, ['ok' => false, 'error' => 'Geçersiz UTC tarih/saat.']);
}

$atSql = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

try {
    $pdo = nmsDb();

    $stmt = $pdo->prepare(
        "SELECT
            nms_id, series, number, year, notam_type, classification,
            location, icao_location, selection_code, traffic, purpose, scope,
            effective_start, effective_end, effective_end_raw,
            lower_limit, upper_limit, radius_nm, notam_text, status, last_updated
         FROM notams
         WHERE source = 'FAA_NMS'
           AND environment = 'production'
           AND status <> 'cancelled'
           AND (
                UPPER(COALESCE(icao_location, '')) = :icao1
                OR UPPER(COALESCE(location, '')) = :icao2
           )
           AND (effective_start IS NULL OR effective_start <= :at_start)
           AND (
                UPPER(COALESCE(effective_end_raw, '')) = 'PERM'
                OR effective_end IS NULL
                OR effective_end >= :at_end
           )
         ORDER BY
           CASE WHEN UPPER(COALESCE(effective_end_raw, '')) = 'PERM' THEN 1 ELSE 0 END,
           effective_start DESC,
           series,
           number
         LIMIT 200"
    );

    $stmt->execute([
        'icao1' => $icao,
        'icao2' => $icao,
        'at_start' => $atSql,
        'at_end' => $atSql,
    ]);

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $series = trim((string)($row['series'] ?? ''));
        $number = trim((string)($row['number'] ?? ''));
        $year = trim((string)($row['year'] ?? ''));
        $ident = trim($series . $number . ($year !== '' ? '/' . substr($year, -2) : ''));

        $items[] = [
            'id' => $row['nms_id'],
            'ident' => $ident !== '' ? $ident : $row['nms_id'],
            'type' => $row['notam_type'],
            'classification' => $row['classification'],
            'location' => $row['location'],
            'icaoLocation' => $row['icao_location'],
            'selectionCode' => $row['selection_code'],
            'traffic' => $row['traffic'],
            'purpose' => $row['purpose'],
            'scope' => $row['scope'],
            'effectiveStart' => $row['effective_start'],
            'effectiveEnd' => $row['effective_end'],
            'effectiveEndRaw' => $row['effective_end_raw'],
            'lowerLimit' => $row['lower_limit'],
            'upperLimit' => $row['upper_limit'],
            'radiusNm' => $row['radius_nm'] !== null ? (float)$row['radius_nm'] : null,
            'text' => $row['notam_text'],
            'status' => $row['status'],
            'lastUpdated' => $row['last_updated'],
        ];
    }

    out(200, [
        'ok' => true,
        'icao' => $icao,
        'atUtc' => $at->format(DateTimeInterface::ATOM),
        'count' => count($items),
        'items' => $items,
    ]);
} catch (Throwable $e) {
    out(500, [
        'ok' => false,
        'error' => 'Airport NOTAM listesi alınamadı.'
    ]);
}
