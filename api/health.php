<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

$started = microtime(true);
$status = [
    'php' => ['ok' => true, 'version' => PHP_VERSION],
    'database' => ['ok' => false],
    'navdata' => ['ok' => false],
    'notam' => ['ok' => false],
];

try {
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('pdo_mysql unavailable');
    $path = dirname(__DIR__, 3) . '/data.php';
    if (!is_file($path)) throw new RuntimeException('config unavailable');
    $cfg = require $path;
    if (!is_array($cfg)) throw new RuntimeException('config invalid');

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['database']),
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->query('SELECT 1')->fetchColumn();
    $status['database'] = ['ok' => true];

    try {
        $navCount = (int)$pdo->query('SELECT COUNT(*) FROM nav_points')->fetchColumn();
        $status['navdata'] = ['ok' => true, 'points' => $navCount];
    } catch (Throwable $e) {
        error_log('[health-navdata] ' . $e->getMessage());
    }

    try {
        $notamCount = (int)$pdo->query("SELECT COUNT(*) FROM notams WHERE source='FAA_NMS' AND environment='production'")->fetchColumn();
        $status['notam'] = ['ok' => true, 'records' => $notamCount];
    } catch (Throwable $e) {
        error_log('[health-notam] ' . $e->getMessage());
    }
} catch (Throwable $e) {
    error_log('[health-db] ' . $e->getMessage());
}

$ok = $status['database']['ok'] && $status['navdata']['ok'] && $status['notam']['ok'];
$payload = [
    'ok' => $ok,
    'timeUtc' => gmdate('c'),
    'durationMs' => round((microtime(true) - $started) * 1000, 1),
    'services' => $status,
];

$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$format = strtolower(trim((string)($_GET['format'] ?? '')));
$wantsHtml = $format !== 'json' && str_contains($accept, 'text/html');

http_response_code($ok ? 200 : 503);

if (!$wantsHtml) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stateText(bool $ok): string {
    return $ok ? 'OK' : 'ERROR';
}

?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#000000">
  <meta name="robots" content="noindex,nofollow">
  <title>Health | YulCaribe</title>
  <link rel="stylesheet" href="/main/app.css?v=2">
</head>
<body data-page="health">
  <header class="app-header">
    <a class="app-brand" href="/main/">YulCaribe</a>
  </header>

  <main class="app-main">
    <section class="page-section" aria-labelledby="health-title">
      <div class="section-title-row">
        <h1 id="health-title">Health</h1>
        <span class="source-line"><?= h($payload['timeUtc']) ?></span>
      </div>

      <section class="plain-block">
        <div class="plain-block-head">
          <strong>System</strong>
          <span class="badge <?= $ok ? 'valid' : 'cancelled' ?>"><?= h(stateText($ok)) ?></span>
        </div>
        <div class="decode-list">
          <div class="decode-row"><span>PHP</span><strong><?= h(PHP_VERSION) ?></strong></div>
          <div class="decode-row"><span>Database</span><strong><?= h(stateText((bool)$status['database']['ok'])) ?></strong></div>
          <div class="decode-row"><span>Navdata</span><strong><?= h(stateText((bool)$status['navdata']['ok'])) ?><?= isset($status['navdata']['points']) ? ' · ' . h(number_format((int)$status['navdata']['points'], 0, ',', '.')) . ' points' : '' ?></strong></div>
          <div class="decode-row"><span>NOTAM</span><strong><?= h(stateText((bool)$status['notam']['ok'])) ?><?= isset($status['notam']['records']) ? ' · ' . h(number_format((int)$status['notam']['records'], 0, ',', '.')) . ' records' : '' ?></strong></div>
          <div class="decode-row"><span>Response</span><strong><?= h($payload['durationMs']) ?> ms</strong></div>
        </div>
      </section>

      <div class="source-line">JSON API: /main/api/health.php?format=json</div>
    </section>
  </main>

  <nav class="bottom-nav" aria-label="Ana navigasyon">
    <a href="/main/" data-nav="home">HOME</a>
    <a href="/main/map/" data-nav="map">MAP</a>
    <a href="/main/notam/" data-nav="notam">NOTAM</a>
  </nav>

  <script src="/main/app.js?v=1" defer></script>
</body>
</html>
