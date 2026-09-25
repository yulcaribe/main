<?php
declare(strict_types=1);

header('X-YC-API-Version: 1');
header('X-YC-API-Resource: charts');

// Canonical chart/navdata API. During migration this delegates to the proven
// NavMap backend while explicitly preventing NOTAM data from leaking into the
// chart resource.
$action = strtolower(trim((string)($_GET['action'] ?? 'viewport')));

if ($action === 'viewport') {
    $requested = array_filter(array_map(
        static fn(string $v): string => strtolower(trim($v)),
        explode(',', (string)($_GET['layers'] ?? 'airport,navaid,waypoint,airway,sid,star,airspace'))
    ));
    $allowed = ['airport', 'navaid', 'waypoint', 'airway', 'sid', 'star', 'airspace'];
    $layers = array_values(array_intersect($allowed, $requested));
    $_GET['layers'] = implode(',', $layers);
}

require dirname(__DIR__, 2) . '/navmap/backend.php';
