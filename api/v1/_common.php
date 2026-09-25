<?php
declare(strict_types=1);

function ycApiV1Headers(string $cacheControl = 'no-store, max-age=0'): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cacheControl);
    header('X-YC-API-Version: 1');
}

function ycApiV1Respond(int $status, array $payload): never {
    http_response_code($status);
    $payload = ['apiVersion' => 'v1'] + $payload;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ycApiV1Method(string ...$allowed): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowed);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        ycApiV1Respond(405, ['ok' => false, 'error' => 'HTTP method not allowed.']);
    }
}

function ycApiV1String(array $source, string $key, int $maxLength = 200): string {
    $value = trim((string)($source[$key] ?? ''));
    if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLength);
    return substr($value, 0, $maxLength);
}
