<?php
declare(strict_types=1);

/**
 * FAA NMS API client.
 *
 * Secrets must stay outside the repository. Configuration can come from:
 *   1) environment variables, or
 *   2) /home/<cpanel-user>/data.php under an optional "nms" key.
 *
 * Staging and production use the same client code. Only configuration changes.
 */

const NMS_USER_AGENT = 'YulCaribe-NMS/1.0 (+https://yulcaribe.com)';

function nmsPrivateConfig(): array {
    $fileConfig = [];
    $homeRoot = dirname(dirname(dirname(__DIR__)));
    $configPath = $homeRoot . '/data.php';

    if (is_file($configPath)) {
        $root = require $configPath;
        if (is_array($root) && isset($root['nms']) && is_array($root['nms'])) {
            $fileConfig = $root['nms'];
        }
    }

    $env = strtolower(trim((string)(getenv('NMS_ENV') ?: ($fileConfig['env'] ?? 'staging'))));
    if (in_array($env, ['prod', 'production'], true)) {
        $env = 'production';
    } else {
        $env = 'staging';
    }

    $clientId = trim((string)(getenv('NMS_CLIENT_ID') ?: ($fileConfig['client_id'] ?? '')));
    $clientSecret = trim((string)(getenv('NMS_CLIENT_SECRET') ?: ($fileConfig['client_secret'] ?? '')));

    $hosts = [
        'staging' => 'https://api-staging.cgifederal-aim.com',
        'production' => 'https://api-nms.aim.faa.gov',
    ];

    return [
        'env' => $env,
        'host' => $hosts[$env],
        'api_base' => $hosts[$env] . '/nmsapi/v1',
        'auth_url' => $hosts[$env] . '/v1/auth/token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'timeout' => 120,
    ];
}

function nmsPublicStatus(): array {
    $cfg = nmsPrivateConfig();

    $homeRoot = dirname(dirname(dirname(__DIR__)));
    $configPath = $homeRoot . '/data.php';
    $configFileFound = is_file($configPath);
    $rootArrayLoaded = false;
    $nmsSectionFound = false;

    if ($configFileFound) {
        $root = require $configPath;
        $rootArrayLoaded = is_array($root);
        $nmsSectionFound = $rootArrayLoaded
            && isset($root['nms'])
            && is_array($root['nms']);
    }

    return [
        'environment' => $cfg['env'],
        'apiBase' => $cfg['api_base'],
        'authUrl' => $cfg['auth_url'],
        'credentialsConfigured' => $cfg['client_id'] !== '' && $cfg['client_secret'] !== '',
        'diagnostics' => [
            'configFileFound' => $configFileFound,
            'rootArrayLoaded' => $rootArrayLoaded,
            'nmsSectionFound' => $nmsSectionFound,
            'clientIdConfigured' => $cfg['client_id'] !== '',
            'clientSecretConfigured' => $cfg['client_secret'] !== '',
        ],
    ];
}

function nmsCacheDir(): string {
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'yulcaribe_nms';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir;
}

function nmsTokenCachePath(array $cfg): string {
    $identity = $cfg['env'] . '|' . $cfg['client_id'];
    return nmsCacheDir() . DIRECTORY_SEPARATOR . 'token_' . sha1($identity) . '.json';
}

function nmsReadCachedToken(array $cfg): ?array {
    if ($cfg['client_id'] === '') {
        return null;
    }

    $path = nmsTokenCachePath($cfg);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $token = (string)($data['accessToken'] ?? '');
    $expiresAt = (int)($data['expiresAt'] ?? 0);

    // Keep a safety margin so a token does not expire during an API request.
    if ($token === '' || $expiresAt <= time() + 60) {
        return null;
    }

    return [
        'access_token' => $token,
        'expires_at' => $expiresAt,
        'cache' => true,
    ];
}

function nmsWriteCachedToken(array $cfg, string $token, int $expiresIn): void {
    if ($token === '') {
        return;
    }

    $path = nmsTokenCachePath($cfg);
    $payload = [
        'accessToken' => $token,
        'expiresAt' => time() + max(1, $expiresIn),
        'savedAt' => time(),
    ];

    @file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @chmod($path, 0600);
}

function nmsAccessToken(bool $forceRefresh = false): array {
    $cfg = nmsPrivateConfig();

    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        return [
            'ok' => false,
            'status' => 503,
            'error' => 'FAA NMS credentials are not configured.',
        ];
    }

    if (!$forceRefresh) {
        $cached = nmsReadCachedToken($cfg);
        if ($cached !== null) {
            return ['ok' => true] + $cached;
        }
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'PHP cURL extension is not enabled.',
        ];
    }

    $ch = curl_init($cfg['auth_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(
            ['grant_type' => 'client_credentials'],
            '',
            '&',
            PHP_QUERY_RFC3986
        ),
        CURLOPT_USERPWD => $cfg['client_id'] . ':' . $cfg['client_secret'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_USERAGENT => NMS_USER_AGENT,
        CURLOPT_ENCODING => '',
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status ?: 502,
            'error' => $error !== '' ? $error : ('NMS auth HTTP ' . $status),
        ];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'status' => 502,
            'error' => 'NMS authentication response is not valid JSON.',
        ];
    }

    $token = trim((string)($data['access_token'] ?? ''));
    $expiresIn = (int)($data['expires_in'] ?? 0);

    if ($token === '') {
        return [
            'ok' => false,
            'status' => 502,
            'error' => 'NMS authentication response did not contain an access_token.',
        ];
    }

    nmsWriteCachedToken($cfg, $token, $expiresIn > 0 ? $expiresIn : 1799);

    return [
        'ok' => true,
        'access_token' => $token,
        'expires_at' => time() + ($expiresIn > 0 ? $expiresIn : 1799),
        'cache' => false,
    ];
}

function nmsGet(
    string $path,
    array $query = [],
    ?string $responseFormat = 'GEOJSON',
    bool $retryAuth = true
): array {
    $cfg = nmsPrivateConfig();
    $auth = nmsAccessToken(false);

    if (!($auth['ok'] ?? false)) {
        return $auth;
    }

    $path = '/' . ltrim($path, '/');
    $url = $cfg['api_base'] . $path;
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = [
        'Authorization: Bearer ' . $auth['access_token'],
        'Accept: application/json, application/geo+json, application/octet-stream;q=0.8, */*;q=0.5',
    ];

    if ($responseFormat !== null) {
        $format = strtoupper(trim($responseFormat));
        if (!in_array($format, ['AIXM', 'GEOJSON'], true)) {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Invalid nmsResponseFormat.',
            ];
        }
        $headers[] = 'nmsResponseFormat: ' . $format;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => NMS_USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($status === 401 && $retryAuth) {
        $fresh = nmsAccessToken(true);
        if (!($fresh['ok'] ?? false)) {
            return $fresh;
        }
        return nmsGet($path, $query, $responseFormat, false);
    }

    if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status ?: 502,
            'error' => $error !== '' ? $error : ('NMS HTTP ' . $status),
        ];
    }

    $decoded = json_decode((string)$body, true);
    $isJson = is_array($decoded) || trim((string)$body) === 'null';

    return [
        'ok' => true,
        'status' => $status,
        'contentType' => $contentType,
        'data' => $isJson ? $decoded : null,
        'body' => $isJson ? null : (string)$body,
    ];
}
