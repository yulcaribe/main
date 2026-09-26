<?php
declare(strict_types=1);

/**
 * Single public Pilot Briefing API endpoint.
 * The implementation lives under lib/briefing; no alternate briefing API
 * endpoints are required or exposed.
 */
$root=dirname(__DIR__,2);
require_once $root.'/lib/briefing/auto-route.php';
require_once $root.'/lib/briefing/notam.php';
require $root.'/lib/briefing/engine.php';
