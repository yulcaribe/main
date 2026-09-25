<?php
declare(strict_types=1);

$defaultBox = '35.670806,38.425248,29.282641,31.772037';
$box = isset($_GET['box']) && preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $_GET['box'])
    ? $_GET['box']
    : $defaultBox;

$format = ($_GET['format'] ?? 'json') === 'binary' ? 'binary' : 'json';

$target = $format === 'binary'
    ? "https://globe.theairtraffic.com/re-api/?binCraft&zstd&box=" . rawurlencode($box)
    : "https://globe.theairtraffic.com/re-api/?json&box=" . rawurlencode($box);

$status = 0;
$error = '';
$body = '';
$headers = [];
$contentType = '';
$elapsed = 0.0;

if (!function_exists('curl_init')) {
    $error = 'PHP cURL eklentisi bu sunucuda aktif değil.';
} else {
    $ch = curl_init($target);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/154 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/plain,*/*',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_HEADERFUNCTION => function($curl, $headerLine) use (&$headers) {
            $len = strlen($headerLine);
            $line = trim($headerLine);
            if ($line !== '') {
                $headers[] = $line;
            }
            return $len;
        },
    ]);

    $start = microtime(true);
    $body = curl_exec($ch);
    $elapsed = microtime(true) - $start;

    if ($body === false) {
        $error = curl_error($ch);
        $body = '';
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    curl_close($ch);
}

$jsonPretty = '';
$jsonValid = false;

if ($format === 'json' && $body !== '') {
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $jsonValid = true;
        $jsonPretty = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } else {
        $jsonPretty = $body;
    }
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$ok = $status >= 200 && $status < 300;
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TheAirTraffic Server Test</title>
<style>
    :root {
        color-scheme: dark;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    body {
        margin: 0;
        background: #090b10;
        color: #eef4ff;
        padding: 28px;
    }
    .wrap { max-width: 1100px; margin: 0 auto; }
    .card {
        background: #11151d;
        border: 1px solid #263041;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 18px;
    }
    h1 { margin-top: 0; font-size: 24px; }
    .status {
        display: inline-block;
        padding: 7px 11px;
        border-radius: 999px;
        font-weight: 700;
        background: <?= $ok ? '#12391f' : '#471818' ?>;
        color: <?= $ok ? '#78f29a' : '#ff8a8a' ?>;
    }
    label { display: block; margin: 12px 0 6px; color: #aebbd0; }
    input, select, button {
        box-sizing: border-box;
        background: #0b0e14;
        color: white;
        border: 1px solid #344157;
        border-radius: 10px;
        padding: 10px 12px;
        font: inherit;
    }
    input { width: 100%; }
    button { cursor: pointer; margin-top: 12px; }
    code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    pre {
        white-space: pre-wrap;
        word-break: break-word;
        background: #080a0f;
        border: 1px solid #252d3a;
        border-radius: 12px;
        padding: 14px;
        max-height: 520px;
        overflow: auto;
    }
    .meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
        gap: 10px;
        margin-top: 16px;
    }
    .meta div {
        background: #0b0e14;
        border: 1px solid #222b38;
        border-radius: 10px;
        padding: 10px;
    }
    .small { color: #9aa8bb; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>TheAirTraffic server-side test</h1>

        <form method="get">
            <label>Bounding box</label>
            <input name="box" value="<?= e($box) ?>">

            <label>Format</label>
            <select name="format">
                <option value="json" <?= $format === 'json' ? 'selected' : '' ?>>JSON</option>
                <option value="binary" <?= $format === 'binary' ? 'selected' : '' ?>>binCraft + zstd</option>
            </select>

            <br>
            <button type="submit">Sunucudan test et</button>
        </form>

        <div style="margin-top:18px">
            <span class="status">
                HTTP <?= $status ?: '—' ?> <?= $ok ? 'OK' : 'FAIL' ?>
            </span>
        </div>

        <div class="meta">
            <div><strong>Süre</strong><br><?= number_format($elapsed, 3) ?> sn</div>
            <div><strong>Content-Type</strong><br><?= e($contentType ?: '—') ?></div>
            <div><strong>Boyut</strong><br><?= number_format(strlen($body)) ?> byte</div>
            <div><strong>JSON</strong><br><?= $jsonValid ? 'Geçerli JSON' : ($format === 'json' ? 'Geçersiz / yok' : 'Binary test') ?></div>
        </div>

        <p class="small">
            Hedef: <code><?= e($target) ?></code>
        </p>

        <?php if ($error !== ''): ?>
            <pre><?= e($error) ?></pre>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Response headers</h2>
        <pre><?= e(implode("\n", $headers)) ?></pre>
    </div>

    <div class="card">
        <h2>Response body</h2>

        <?php if ($format === 'binary'): ?>
            <p class="small">
                Binary cevap ekrana ham basılmıyor. İlk 120 byte base64 olarak gösteriliyor.
            </p>
            <pre><?= e(base64_encode(substr($body, 0, 120))) ?></pre>
        <?php else: ?>
            <pre><?= e($jsonPretty ?: '(boş cevap)') ?></pre>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
