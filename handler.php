<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use App\Config;
use App\Mapping\DealMapping;
use App\Mapping\MigrationOffset;
use App\Mapping\UserMapping;
use App\Migration\TransferService;
use App\Runtime;
use App\Support\Logger;

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '2048M');
ini_set('max_input_time', '-1');
ini_set('default_socket_timeout', '3600');
ignore_user_abort(true);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
    session_id('');
}

while (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);
header('X-Accel-Buffering: no');
header('Content-Encoding: none');
header('Cache-Control: no-cache, must-revalidate');
header('Content-Type: text/html; charset=utf-8');

Runtime::init();

$testMode = isset($_GET['test']) && $_GET['test'] === 'true';
Runtime::$testMode = $testMode;
Runtime::$testTaskId = 60;

if ($testMode) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['test_mode' => true, 'fetching_task' => Runtime::$testTaskId], JSON_UNESCAPED_UNICODE);
    flush();
    $iterations = 1;
    Runtime::$tasksPerIteration = 1;
    $startOffset = 0;
    Runtime::$batchSize = 1;
} else {
    $iterations = Config::migrationIterations();
    Runtime::$tasksPerIteration = Config::tasksPerIteration();
    $startOffset = MigrationOffset::get(0);
    Runtime::$batchSize = Config::batchSize();
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    Logger::logError("PHP Error: {$errstr}", [
        'errno' => $errno,
        'file' => $errfile,
        'line' => $errline,
    ], 'WARNING');
    return true;
});

set_exception_handler(function ($e) {
    Logger::logError('Необработанное исключение: ' . $e->getMessage(), [
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Критическая ошибка. Проверьте лог ошибок. Продолжаем работу...\n";
});

try {
    Runtime::$userMapping = UserMapping::load();
    Runtime::$dealMapping = DealMapping::load();

    $allCreatedTasks = [];
    $currentOffset = $startOffset;
    $iteration = 1;
    $totalCreatedThisSession = 0;

    while ($iteration <= $iterations) {
        Logger::checkConnection();
        Logger::keepAlive();
        echo ' ';
        flush();

        try {
            $result = TransferService::transferCompletedTasks($currentOffset, $testMode);

            if ($testMode) {
                $testResult = [
                    'planfix_id' => Runtime::$testTaskId,
                    'bitrix_id' => null,
                    'status' => 'not_found',
                    'name' => 'Task not processed',
                ];
                foreach ($result['tasks'] ?? [] as $task) {
                    if (($task['planfix_id'] ?? 0) == Runtime::$testTaskId) {
                        $testResult = [
                            'planfix_id' => Runtime::$testTaskId,
                            'bitrix_id' => $task['bitrix_id'] ?? null,
                            'status' => !empty($task['bitrix_id']) ? 'success' : 'error',
                            'name' => $task['name'] ?? 'Task ' . Runtime::$testTaskId,
                        ];
                        break;
                    }
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($testResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }

            $newlyCreated = $result['created'];
            $allCreatedTasks = array_merge($allCreatedTasks, $result['tasks']);
            $totalCreatedThisSession += $newlyCreated;

            $currentOffset += Runtime::$tasksPerIteration;
            $iteration++;
            MigrationOffset::save($currentOffset);

            if ($iteration <= $iterations) {
                sleep(5);
            }
        } catch (Exception $e) {
            Logger::logError("Исключение в основной итерации {$iteration}: " . $e->getMessage());
            $iteration++;
            continue;
        }
    }
} catch (Exception $e) {
    Logger::logError('Критическое исключение в основном скрипте: ' . $e->getMessage());
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "ИТОГИ ПЕРЕНОСА ЗАВЕРШЕННЫХ ЗАДАЧ\n";
echo str_repeat('=', 80) . "\n";

$totalCreated = 0;
foreach ($allCreatedTasks ?? [] as $task) {
    if (!empty($task['bitrix_id'])) {
        $totalCreated++;
    }
}

echo "Всего создано завершенных задач в Битрикс24: {$totalCreated}\n";
echo 'Всего обработано задач: ' . count($allCreatedTasks ?? []) . "\n";
echo "Финальное смещение: {$currentOffset}\n";
echo 'Выполнено итераций: ' . (($iteration ?? 1) - 1) . "\n\n";

if (!empty(Runtime::$executionStats['errors'])) {
    echo "СТАТИСТИКА ОШИБОК:\n";
    echo str_repeat('-', 80) . "\n";
    echo 'Всего ошибок: ' . count(Runtime::$executionStats['errors']) . "\n";

    $errorTypes = [];
    foreach (Runtime::$executionStats['errors'] as $error) {
        $severity = $error['severity'] ?? 'UNKNOWN';
        $errorTypes[$severity] = ($errorTypes[$severity] ?? 0) + 1;
    }

    foreach ($errorTypes as $type => $count) {
        echo "{$type}: {$count}\n";
    }
    echo "\n";
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "ПЕРЕНОС ЗАВЕРШЕННЫХ ЗАДАЧ ЗАВЕРШЕН\n";
echo str_repeat('=', 80) . "\n";
echo "Проверьте файл error_log.json для детальной информации об ошибках\n";

try {
    $tempDir = Config::basePath() . '/temp_files';
    if (file_exists($tempDir)) {
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($tempDir)) {
            @rmdir($tempDir);
        }
    }
} catch (Exception $e) {
    Logger::logError('Ошибка при очистке временных файлов: ' . $e->getMessage());
}

try {
    Runtime::$executionStats['end_time'] = date('Y-m-d H:i:s');
    Runtime::$executionStats['duration'] = strtotime(Runtime::$executionStats['end_time']) - strtotime(Runtime::$executionStats['start_time']);
    Runtime::$executionStats['success_rate'] = Runtime::$executionStats['tasks_processed'] > 0
        ? round((Runtime::$executionStats['tasks_created'] / Runtime::$executionStats['tasks_processed']) * 100, 2)
        : 0;

    file_put_contents(
        Config::basePath() . '/execution_stats.json',
        json_encode(Runtime::$executionStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
} catch (Exception $e) {
    // ignore stats save errors
}
