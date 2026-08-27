<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;
use App\Runtime;
use Error;
use Exception;

final class Logger
{
    public static function checkConnection(): bool
    {
        if (connection_aborted()) {
            self::logError('Соединение разорвано');
            exit;
        }

        return true;
    }

    public static function keepAlive(): void
    {
        static $counter = 0;
        $counter++;

        if ($counter % 30 == 0) {
            echo '<!-- keep-alive: ' . date('H:i:s') . " -->\n";
            flush();
        }

        if (connection_aborted()) {
            self::logError('Соединение разорвано клиентом');
            exit;
        }
    }

    public static function saveToJsonFile($data, string $filename): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents(Config::basePath() . '/' . $filename, $json);
    }

    /**
     * Логирует ошибку с продолжением выполнения
     */
    public static function logError($message, $context = [], $severity = 'ERROR'): void
    {
        $errorLogFile = Runtime::$errorLogFile;
        if ($errorLogFile === '') {
            $errorLogFile = Config::basePath() . '/error_log.json';
            Runtime::$errorLogFile = $errorLogFile;
        }

        $errorData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ];

        Runtime::$executionStats['errors'][] = $errorData;

        $existingLogs = [];
        if (file_exists($errorLogFile)) {
            $existingContent = file_get_contents($errorLogFile);
            if ($existingContent) {
                $existingLogs = json_decode($existingContent, true) ?: [];
            }
        }

        $existingLogs[] = $errorData;
        file_put_contents($errorLogFile, json_encode($existingLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        echo "[{$severity}] {$message}\n";
        if (!empty($context)) {
            echo 'Контекст: ' . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    /**
     * Безопасное выполнение функции с обработкой ошибок
     */
    public static function safeExecute($function, $args = [], $default = null)
    {
        try {
            return call_user_func_array($function, $args);
        } catch (Exception $e) {
            self::logError('Ошибка в функции: ' . $e->getMessage(), [
                'function' => is_string($function) ? $function : 'anonymous',
                'args' => $args,
                'trace' => $e->getTraceAsString(),
            ]);
            return $default;
        } catch (Error $e) {
            self::logError('Критическая ошибка в функции: ' . $e->getMessage(), [
                'function' => is_string($function) ? $function : 'anonymous',
                'args' => $args,
                'trace' => $e->getTraceAsString(),
            ]);
            return $default;
        }
    }
}
