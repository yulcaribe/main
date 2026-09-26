<?php
declare(strict_types=1);

/**
 * Single public Pilot Briefing API endpoint.
 * The Briefing implementation lives under /briefing; no alternate Briefing
 * API endpoints are required or exposed.
 */
$root=dirname(__DIR__,2);
require_once $root.'/briefing/internal/auto-route.php';
require_once $root.'/briefing/internal/notam.php';
require $root.'/briefing/engine.php';
