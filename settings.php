<?php

declare(strict_types=1);

/**
 * Load .env into $_ENV / putenv without Composer (used by crest settings).
 */
function planfix_load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key === '') {
            continue;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$envFile = __DIR__ . '/.env';
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (is_readable($envFile)) {
        Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    }
} else {
    planfix_load_env($envFile);
}

$clientId = $_ENV['C_REST_CLIENT_ID'] ?? getenv('C_REST_CLIENT_ID') ?: '';
$clientSecret = $_ENV['C_REST_CLIENT_SECRET'] ?? getenv('C_REST_CLIENT_SECRET') ?: '';

define('C_REST_CLIENT_ID', (string) $clientId);
define('C_REST_CLIENT_SECRET', (string) $clientSecret);
define('C_REST_BLOCK_LOG', true);
