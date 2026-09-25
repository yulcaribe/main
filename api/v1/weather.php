<?php
declare(strict_types=1);

// Canonical weather API. METAR/TAF currently share the existing resilient AWC
// fetch/cache implementation; product-specific v1 endpoints can be added later
// without changing clients that use this combined resource.
require dirname(__DIR__) . '/weather.php';
