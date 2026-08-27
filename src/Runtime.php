<?php

declare(strict_types=1);

namespace App;

/**
 * Shared runtime state for migration scripts (replaces loose globals).
 */
final class Runtime
{
    /** @var array<string, int|string> */
    public static array $userMapping = [];

    /** @var array<int|string, int|string> */
    public static array $dealMapping = [];

    public static int $batchSize = 50;

    public static int $tasksPerIteration = 100;

    public static ?int $testTaskId = null;

    public static bool $testMode = false;

    /** @var array<string, mixed> */
    public static array $executionStats = [
        'start_time' => null,
        'errors' => [],
        'warnings' => [],
        'tasks_processed' => 0,
        'tasks_created' => 0,
        'tasks_failed' => 0,
    ];

    public static string $errorLogFile = '';

    public static function init(): void
    {
        self::$errorLogFile = Config::basePath() . '/error_log.json';
        self::$executionStats['start_time'] = date('Y-m-d H:i:s');
        self::$batchSize = Config::batchSize();
        self::$tasksPerIteration = Config::tasksPerIteration();
    }
}
