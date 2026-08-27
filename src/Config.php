<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public static function basePath(): string
    {
        return dirname(__DIR__);
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function planfixBaseUrl(): string
    {
        $url = self::env('PLANFIX_BASE_URL', '');
        if ($url === '') {
            throw new \RuntimeException('PLANFIX_BASE_URL is not set in .env');
        }

        return rtrim($url, '/') . '/';
    }

    public static function planfixApiToken(): string
    {
        $token = self::env('PLANFIX_API_TOKEN', '');
        if ($token === '') {
            throw new \RuntimeException('PLANFIX_API_TOKEN is not set in .env');
        }

        return $token;
    }

    public static function defaultBitrixUserId(): int
    {
        return (int) self::env('DEFAULT_BITRIX_USER_ID', '627');
    }

    public static function migrationIterations(): int
    {
        return (int) self::env('MIGRATION_ITERATIONS', '1000');
    }

    public static function tasksPerIteration(): int
    {
        return (int) self::env('MIGRATION_TASKS_PER_ITERATION', '100');
    }

    public static function batchSize(): int
    {
        return (int) self::env('MIGRATION_BATCH_SIZE', '50');
    }
}
