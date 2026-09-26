<?php
declare(strict_types=1);

function nmsHealthSessionDir(): string {
    $homeRoot = dirname(__DIR__, 5);
    $dir = $homeRoot . '/.yulcaribe_sessions/health';

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return '';
    }

    @chmod($dir, 0700);
    return is_writable($dir) ? $dir : '';
}

function nmsHealthSessionStart(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $sessionDir = nmsHealthSessionDir();
    if ($sessionDir !== '') {
        session_save_path($sessionDir);
    }

    session_name('yulcaribe_health');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/main/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    if (!@session_start() || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Health session başlatılamadı. save_path=' . session_save_path());
    }
}

function nmsAdminKey(): string {
    $envKey = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: getenv('NMS_ADMIN_KEY') ?: ''));
    if ($envKey !== '') return $envKey;

    $homeRoot = dirname(__DIR__, 5);
    $configPath = $homeRoot . '/data.php';
    if (!is_file($configPath)) return '';

    $root = require $configPath;
    if (!is_array($root)) return '';

    $healthKey = isset($root['health']) && is_array($root['health'])
        ? trim((string)($root['health']['admin_key'] ?? ''))
        : '';
    if ($healthKey !== '') return $healthKey;

    return isset($root['nms']) && is_array($root['nms'])
        ? trim((string)($root['nms']['admin_key'] ?? ''))
        : '';
}

function nmsHealthPasswordHash(): string {
    $homeRoot = dirname(__DIR__, 5);
    $configPath = $homeRoot . '/data.php';
    if (!is_file($configPath)) return '';

    $root = require $configPath;
    if (!is_array($root) || !isset($root['health']) || !is_array($root['health'])) return '';

    return trim((string)($root['health']['password_hash'] ?? ''));
}

function nmsHealthVerifyPassword(string $provided): bool {
    $provided = trim($provided);
    if ($provided === '') return false;

    $explicitEnv = trim((string)(getenv('HEALTH_ADMIN_KEY') ?: ''));
    if ($explicitEnv !== '') return hash_equals($explicitEnv, $provided);

    $hash = nmsHealthPasswordHash();
    if ($hash !== '') return password_verify($provided, $hash);

    $legacy = nmsAdminKey();
    return $legacy !== '' && hash_equals($legacy, $provided);
}

function nmsHealthAuthenticated(): bool {
    nmsHealthSessionStart();
    return ($_SESSION['nms_health_authenticated'] ?? false) === true;
}

function nmsHealthLogin(string $provided): bool {
    nmsHealthSessionStart();

    if (!nmsHealthVerifyPassword($provided)) {
        usleep(250000);
        return false;
    }

    if (!session_regenerate_id(true)) {
        return false;
    }

    $_SESSION['nms_health_authenticated'] = true;
    $_SESSION['nms_health_login_at'] = time();

    return session_write_close();
}

function nmsHealthLogout(): void {
    nmsHealthSessionStart();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/main/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => 'Strict',
        ]);
    }

    session_destroy();
}
