<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, max-age=0');

$file = __DIR__ . '/index.html';
$html = @file_get_contents($file);
if ($html === false) {
    http_response_code(500);
    echo 'Pilot Briefing UI unavailable.';
    exit;
}

$html = str_replace(
    'YulCaribe Pilot Briefing: rota, ETD, cruise flight level, METAR/TAF ve rota ile ilişkili SIGMET analizi.',
    'YulCaribe Pilot Briefing: estimated navdata route, ETD, cruise flight level, METAR/TAF, SIGMET, WAFS ve route-aware NOTAM analizi.',
    $html
);
$html = str_replace(
    "Rota boyunca temsilci METAR/TAF'lar ve rotaya gerçekten yakın aktif SIGMET'ler. Cruise seviyesi ve zaman bağlamı ayrıca değerlendirilir.",
    "Rota boyunca METAR/TAF, SIGMET, WAFS, model weather ve route-aware NOTAM analizi. OFP yoksa great-circle yalnızca rehber olarak kullanılır; mümkünse MariaDB airway graph üzerinden estimated navdata route üretilir.",
    $html
);
$html = str_replace(
    '<div><strong>OFP / ROUTE</strong><small>İsteğe bağlı. Boşsa great-circle tahmini.</small></div>',
    '<div><strong>OFP / ROUTE</strong><small>İsteğe bağlı. Boşsa great-circle rehberli navdata airway tahmini; uygun graph bulunamazsa great-circle fallback.</small></div>',
    $html
);
$html = str_replace(
    '<span>Data: NOAA / NWS Aviation Weather Center + NOAA/NCEP GFS</span>',
    '<span>Data: NOAA / NWS Aviation Weather Center + NOAA/NCEP GFS + FAA NMS + YulCaribe navdata</span>',
    $html
);
$html = str_replace(
    'await load("/main/briefing/briefing.js?v=15");',
    "await load(\"/main/briefing/notam-ui.js?v=1\");\n          await load(\"/main/briefing/briefing.js?v=16\");",
    $html
);

echo $html;
