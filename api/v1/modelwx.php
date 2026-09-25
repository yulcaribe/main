<?php
declare(strict_types=1);

header('X-YC-API-Version: 1');
header('X-YC-API-Resource: modelwx');

require dirname(__DIR__, 2) . '/briefing/modelwx.php';
