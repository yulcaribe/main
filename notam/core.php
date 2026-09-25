<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/nms_store.php';

const YC_NOTAM_SOURCE = 'FAA_NMS';
const YC_NOTAM_ENVIRONMENT = 'production';
const YC_NOTAM_RETENTION_DAYS = 3;

function ycNotamUtc(?string $raw, ?DateTimeImmutable $fallback = null): DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return $fallback ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone('UTC'));
}

function ycNotamIdent(array $row): string {
    $series = strtoupper(trim((string)($row['series'] ?? '')));
    $number = trim((string)($row['number'] ?? ''));
    $year = trim((string)($row['year'] ?? ''));

    if ($series === '' && $number === '') {
        return (string)($row['nms_id'] ?? '');
    }

    $ident = $series . $number;
    if ($year !== '' && !preg_match('/\/\d{2}$/', $ident)) {
        $ident .= '/' . substr($year, -2);
    }
    return $ident;
}

function ycNotamIdentifierKey(array $row): ?string {
    $series = strtoupper(trim((string)($row['series'] ?? '')));
    $numberRaw = strtoupper(trim((string)($row['number'] ?? '')));
    $yearRaw = trim((string)($row['year'] ?? ''));

    if ($series === '' || $numberRaw === '') return null;
    if (!preg_match('/([0-9]{4})/', $numberRaw, $numberMatch)) return null;

    $year2 = '';
    if (preg_match('/\/([0-9]{2})\b/', $numberRaw, $yearMatch)) {
        $year2 = $yearMatch[1];
    } elseif ($yearRaw !== '' && preg_match('/([0-9]{2})$/', $yearRaw, $yearMatch)) {
        $year2 = $yearMatch[1];
    }
    if ($year2 === '') return null;

    return substr($series, 0, 1) . $numberMatch[1] . '/' . $year2;
}

function ycNotamCancellationTargetsAfter(PDO $pdo, DateTimeImmutable $at): array {
    $stmt = $pdo->prepare(
        "SELECT notam_text, COALESCE(effective_start, last_updated) AS cancellation_time
         FROM notams
         WHERE source = :source
           AND environment = :environment
           AND status = 'cancelled'
           AND UPPER(COALESCE(notam_type, '')) = 'C'
           AND COALESCE(effective_start, last_updated) > :at"
    );
    $stmt->execute([
        'source' => YC_NOTAM_SOURCE,
        'environment' => YC_NOTAM_ENVIRONMENT,
        'at' => $at->format('Y-m-d H:i:s'),
    ]);

    $targets = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $text = (string)($row['notam_text'] ?? '');
        if (!preg_match('/\bNOTAMC\s+([A-Z])([0-9]{4})\/([0-9]{2})\b/i', $text, $m)) continue;

        $key = strtoupper($m[1]) . $m[2] . '/' . $m[3];
        $time = (string)($row['cancellation_time'] ?? '');
        if ($time === '') continue;

        if (!isset($targets[$key]) || strcmp($time, $targets[$key]) < 0) {
            $targets[$key] = $time;
        }
    }
    return $targets;
}

function ycNotamFilterHistoricalRows(array $rows, DateTimeImmutable $at, array $futureCancellations): array {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $timeTravel = abs($at->getTimestamp() - $now->getTimestamp()) > 300;
    if (!$timeTravel) return $rows;

    $filtered = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') !== 'cancelled') {
            $filtered[] = $row;
            continue;
        }

        if (strtoupper((string)($row['notam_type'] ?? '')) === 'C') continue;

        $key = ycNotamIdentifierKey($row);
        if ($key !== null && isset($futureCancellations[$key])) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

function ycNotamCsvValues(?string $raw, string $pattern, int $max = 20): array {
    $items = array_filter(array_map(
        static fn(string $v): string => strtoupper(trim($v)),
        explode(',', (string)$raw)
    ));

    $out = [];
    foreach ($items as $item) {
        if (preg_match($pattern, $item)) $out[$item] = true;
        if (count($out) >= $max) break;
    }
    return array_keys($out);
}

function ycNotamAddInFilter(array &$where, array &$params, string $column, string $prefix, array $values): void {
    if (!$values) return;

    $holders = [];
    foreach ($values as $i => $value) {
        $key = $prefix . $i;
        $holders[] = ':' . $key;
        $params[$key] = $value;
    }
    $where[] = $column . ' IN (' . implode(',', $holders) . ')';
}

function ycNotamBaseSelect(bool $includeText = true, bool $includeGeometry = false): string {
    $columns = [
        'n.nms_id', 'n.series', 'n.number', 'n.year', 'n.notam_type', 'n.classification',
        'n.affected_fir', 'n.location', 'n.icao_location', 'n.account_id',
        'n.selection_code', 'n.traffic', 'n.purpose', 'n.scope',
        'n.minimum_fl', 'n.maximum_fl',
        'n.effective_start', 'n.effective_end', 'n.effective_end_raw',
        'n.estimated', 'n.schedule', 'n.lower_limit', 'n.upper_limit',
        'n.coordinates_raw', 'n.radius_nm', 'n.status', 'n.last_updated'
    ];

    if ($includeText) $columns[] = 'n.notam_text';
    if ($includeGeometry) $columns[] = 'ST_AsGeoJSON(n.geometry, 6) AS geometry';

    return implode(', ', $columns);
}

function ycNotamFormatRow(array $row, bool $includeText = true, bool $includeGeometry = false): array {
    $item = [
        'id' => (string)($row['nms_id'] ?? ''),
        'ident' => ycNotamIdent($row),
        'type' => $row['notam_type'] ?? null,
        'classification' => $row['classification'] ?? null,
        'fir' => $row['affected_fir'] ?? null,
        'location' => $row['location'] ?? null,
        'icaoLocation' => $row['icao_location'] ?? null,
        'accountId' => $row['account_id'] ?? null,
        'selectionCode' => $row['selection_code'] ?? null,
        'traffic' => $row['traffic'] ?? null,
        'purpose' => $row['purpose'] ?? null,
        'scope' => $row['scope'] ?? null,
        'minimumFl' => isset($row['minimum_fl']) ? (int)$row['minimum_fl'] : null,
        'maximumFl' => isset($row['maximum_fl']) ? (int)$row['maximum_fl'] : null,
        'effectiveStart' => $row['effective_start'] ?? null,
        'effectiveEnd' => $row['effective_end'] ?? null,
        'effectiveEndRaw' => $row['effective_end_raw'] ?? null,
        'estimated' => $row['estimated'] ?? null,
        'schedule' => $row['schedule'] ?? null,
        'lowerLimit' => $row['lower_limit'] ?? null,
        'upperLimit' => $row['upper_limit'] ?? null,
        'coordinates' => $row['coordinates_raw'] ?? null,
        'radiusNm' => isset($row['radius_nm']) && $row['radius_nm'] !== null ? (float)$row['radius_nm'] : null,
        'status' => $row['status'] ?? null,
        'lastUpdated' => $row['last_updated'] ?? null,
    ];

    if ($includeText) $item['text'] = $row['notam_text'] ?? null;
    if ($includeGeometry) {
        $geometry = json_decode((string)($row['geometry'] ?? ''), true);
        $item['geometry'] = is_array($geometry) ? $geometry : null;
    }

    return $item;
}

function ycNotamList(PDO $pdo, array $input): array {
    $at = ycNotamUtc(isset($input['at']) ? (string)$input['at'] : null);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $timeTravel = abs($at->getTimestamp() - $now->getTimestamp()) > 300;

    $state = strtolower(trim((string)($input['state'] ?? 'valid')));
    if (!in_array($state, ['valid', 'future', 'expired', 'cancelled', 'all'], true)) $state = 'valid';

    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, min(200, (int)($input['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $includeText = !isset($input['include_text']) || (string)$input['include_text'] !== '0';
    $includeGeometry = isset($input['include_geometry']) && (string)$input['include_geometry'] === '1';

    $where = [
        'n.source = :source',
        'n.environment = :environment',
    ];
    $params = [
        'source' => YC_NOTAM_SOURCE,
        'environment' => YC_NOTAM_ENVIRONMENT,
    ];

    if ($state === 'valid') {
        if ($timeTravel) {
            $where[] = "(n.status <> 'cancelled' OR UPPER(COALESCE(n.notam_type, '')) <> 'C')";
        } else {
            $where[] = "n.status <> 'cancelled'";
        }
        $where[] = '(n.effective_start IS NULL OR n.effective_start <= :at_start)';
        $where[] = "(UPPER(COALESCE(n.effective_end_raw, '')) = 'PERM' OR n.effective_end IS NULL OR n.effective_end >= :at_end)";
        $params['at_start'] = $at->format('Y-m-d H:i:s');
        $params['at_end'] = $at->format('Y-m-d H:i:s');
    } elseif ($state === 'future') {
        $where[] = "n.status <> 'cancelled'";
        $where[] = 'n.effective_start IS NOT NULL AND n.effective_start > :state_at';
        $params['state_at'] = $at->format('Y-m-d H:i:s');
    } elseif ($state === 'expired') {
        $where[] = "n.status <> 'cancelled'";
        $where[] = 'n.effective_end IS NOT NULL AND n.effective_end < :state_at';
        $where[] = "UPPER(COALESCE(n.effective_end_raw, '')) <> 'PERM'";
        $params['state_at'] = $at->format('Y-m-d H:i:s');
    } elseif ($state === 'cancelled') {
        $where[] = "n.status = 'cancelled'";
    }

    $icaoValues = ycNotamCsvValues(
        isset($input['icao']) ? (string)$input['icao'] : null,
        '/^[A-Z0-9]{4}$/'
    );
    if ($icaoValues) {
        $icaoHolders = [];
        foreach ($icaoValues as $i => $value) {
            $key = 'icao' . $i;
            $icaoHolders[] = ':' . $key;
            $params[$key] = $value;
        }
        $icaoIn = '(' . implode(',', $icaoHolders) . ')';
        $where[] = "(UPPER(COALESCE(n.icao_location, '')) IN {$icaoIn}
                    OR UPPER(COALESCE(n.location, '')) IN {$icaoIn})";
    }
    ycNotamAddInFilter(
        $where,
        $params,
        'UPPER(COALESCE(n.affected_fir, \'\'))',
        'fir',
        ycNotamCsvValues(isset($input['fir']) ? (string)$input['fir'] : null, '/^[A-Z0-9]{4}$/')
    );
    ycNotamAddInFilter(
        $where,
        $params,
        'UPPER(COALESCE(n.notam_type, \'\'))',
        'type',
        ycNotamCsvValues(isset($input['type']) ? (string)$input['type'] : null, '/^[A-Z]{1,4}$/')
    );
    ycNotamAddInFilter(
        $where,
        $params,
        'UPPER(COALESCE(n.classification, \'\'))',
        'class',
        ycNotamCsvValues(isset($input['classification']) ? (string)$input['classification'] : null, '/^[A-Z0-9_ -]{1,40}$/')
    );
    ycNotamAddInFilter(
        $where,
        $params,
        'UPPER(COALESCE(n.scope, \'\'))',
        'scope',
        ycNotamCsvValues(isset($input['scope']) ? (string)$input['scope'] : null, '/^[A-Z]{1,8}$/')
    );
    ycNotamAddInFilter(
        $where,
        $params,
        'UPPER(COALESCE(n.traffic, \'\'))',
        'traffic',
        ycNotamCsvValues(isset($input['traffic']) ? (string)$input['traffic'] : null, '/^[A-Z]{1,8}$/')
    );

    $purpose = strtoupper(trim((string)($input['purpose'] ?? '')));
    if ($purpose !== '' && preg_match('/^[A-Z]{1,12}$/', $purpose)) {
        $where[] = 'UPPER(COALESCE(n.purpose, \'\')) LIKE :purpose';
        $params['purpose'] = '%' . $purpose . '%';
    }

    $selection = strtoupper(trim((string)($input['selection_code'] ?? '')));
    if ($selection !== '' && preg_match('/^[A-Z0-9]{1,12}$/', $selection)) {
        $where[] = 'UPPER(COALESCE(n.selection_code, \'\')) LIKE :selection';
        $params['selection'] = '%' . $selection . '%';
    }

    if (isset($input['min_fl']) && is_numeric($input['min_fl'])) {
        $where[] = '(n.maximum_fl IS NULL OR n.maximum_fl >= :min_fl)';
        $params['min_fl'] = max(0, min(999, (int)$input['min_fl']));
    }
    if (isset($input['max_fl']) && is_numeric($input['max_fl'])) {
        $where[] = '(n.minimum_fl IS NULL OR n.minimum_fl <= :max_fl)';
        $params['max_fl'] = max(0, min(999, (int)$input['max_fl']));
    }

    $search = trim((string)($input['q'] ?? ''));
    if ($search !== '') {
        if (function_exists('mb_substr')) $search = mb_substr($search, 0, 100);
        else $search = substr($search, 0, 100);
        $where[] = "(
            n.notam_text LIKE :search
            OR n.selection_code LIKE :search
            OR n.location LIKE :search
            OR n.icao_location LIKE :search
            OR n.affected_fir LIKE :search
            OR CONCAT(COALESCE(n.series,''), COALESCE(n.number,'')) LIKE :search
        )";
        $params['search'] = '%' . $search . '%';
    }

    $sort = strtolower(trim((string)($input['sort'] ?? 'updated_desc')));
    $orderBy = match ($sort) {
        'start_asc' => 'n.effective_start ASC, n.last_updated DESC',
        'start_desc' => 'n.effective_start DESC, n.last_updated DESC',
        'ident' => 'n.series ASC, n.number ASC, n.year DESC',
        default => 'n.last_updated DESC, n.effective_start DESC',
    };

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notams n WHERE ' . $whereSql);
    $countStmt->execute($params);
    $sqlTotal = (int)$countStmt->fetchColumn();

    $select = ycNotamBaseSelect($includeText, $includeGeometry);
    $sql = 'SELECT ' . $select . '
            FROM notams n
            WHERE ' . $whereSql . '
            ORDER BY ' . $orderBy . '
            LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($state === 'valid' && $timeTravel) {
        $futureCancellations = ycNotamCancellationTargetsAfter($pdo, $at);
        $rows = ycNotamFilterHistoricalRows($rows, $at, $futureCancellations);
    }

    $items = array_map(
        static fn(array $row): array => ycNotamFormatRow($row, $includeText, $includeGeometry),
        $rows
    );

    return [
        'atUtc' => $at->format(DateTimeInterface::ATOM),
        'state' => $state,
        'page' => $page,
        'limit' => $limit,
        'sqlTotal' => $sqlTotal,
        'items' => $items,
        'filters' => [
            'icao' => $input['icao'] ?? null,
            'fir' => $input['fir'] ?? null,
            'type' => $input['type'] ?? null,
            'classification' => $input['classification'] ?? null,
            'scope' => $input['scope'] ?? null,
            'traffic' => $input['traffic'] ?? null,
            'purpose' => $input['purpose'] ?? null,
            'selectionCode' => $input['selection_code'] ?? null,
            'minFl' => $input['min_fl'] ?? null,
            'maxFl' => $input['max_fl'] ?? null,
            'q' => $search !== '' ? $search : null,
            'sort' => $sort,
        ],
    ];
}

function ycNotamDetail(PDO $pdo, string $id): ?array {
    $id = trim($id);
    if ($id === '' || strlen($id) > 160) return null;

    $stmt = $pdo->prepare(
        'SELECT ' . ycNotamBaseSelect(true, true) . '
         FROM notams n
         WHERE n.source = :source
           AND n.environment = :environment
           AND n.nms_id = :id
         LIMIT 1'
    );
    $stmt->execute([
        'source' => YC_NOTAM_SOURCE,
        'environment' => YC_NOTAM_ENVIRONMENT,
        'id' => $id,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ycNotamFormatRow($row, true, true) : null;
}

function ycNotamFilterOptions(PDO $pdo): array {
    $columns = [
        'type' => 'notam_type',
        'classification' => 'classification',
        'scope' => 'scope',
        'traffic' => 'traffic',
    ];

    $result = [];
    foreach ($columns as $key => $column) {
        $sql = "SELECT DISTINCT UPPER(TRIM(COALESCE({$column}, ''))) AS value
                FROM notams
                WHERE source = :source
                  AND environment = :environment
                  AND {$column} IS NOT NULL
                  AND TRIM({$column}) <> ''
                ORDER BY value
                LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'source' => YC_NOTAM_SOURCE,
            'environment' => YC_NOTAM_ENVIRONMENT,
        ]);
        $result[$key] = array_values(array_filter(array_map(
            static fn(array $row): string => (string)$row['value'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )));
    }

    return $result;
}
