<?php
// task_dumper.php
// Скрипт для сохранения сырой информации о задачах из Planfix в JSON файлы

require_once(__DIR__ . '/crest.php');

// =================== КОНФИГУРАЦИЯ ===================
$baseUrl = rtrim($_ENV['PLANFIX_BASE_URL'] ?? getenv('PLANFIX_BASE_URL') ?: '', '/') . '/';
$apiToken = $_ENV['PLANFIX_API_TOKEN'] ?? getenv('PLANFIX_API_TOKEN') ?: '';
if ($baseUrl === '/' || $apiToken === '') {
    fwrite(STDERR, "Set PLANFIX_BASE_URL and PLANFIX_API_TOKEN in .env\n");
    exit(1);
}

// Настройки партиционирования
$tasksPerPartition = 10000; // Количество задач в одном файле
$outputDirectory = __DIR__ . '/tasks_raw/'; // Директория для сохранения файлов
$statusFile = __DIR__ . '/dumping_status.json'; // Файл статуса

// =================== ЛОГИРОВАНИЕ ===================
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$type}] {$message}\n";
    
    // Также сохраняем в лог файл
    file_put_contents(__DIR__ . '/dump_log.txt', "[{$timestamp}] [{$type}] {$message}\n", FILE_APPEND);
}

// =================== ФУНКЦИИ API ===================
function makePlanfixRequest($url, $apiToken, $data = null) {
    try {
        $ch = curl_init($url);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiToken
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];
        
        if ($data !== null) {
            $options[CURLOPT_POST] = true;
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                throw new Exception("Ошибка кодирования JSON данных для запроса");
            }
            $options[CURLOPT_POSTFIELDS] = $jsonData;
        }
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            logMessage("Ошибка cURL при запросе к Planfix: {$curlError}", 'ERROR');
            return ['success' => false, 'error' => 'Ошибка cURL: ' . $curlError];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("Ошибка декодирования JSON ответа от Planfix: " . json_last_error_msg(), 'ERROR');
            return ['success' => false, 'error' => 'Ошибка JSON: ' . json_last_error_msg()];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $result];
        } else {
            logMessage("HTTP ошибка при запросе к Planfix: код {$httpCode}", 'ERROR');
            return ['success' => false, 'http_code' => $httpCode, 'data' => $result];
        }
    } catch (Exception $e) {
        logMessage("Исключение при выполнении запроса: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'error' => 'Исключение: ' . $e->getMessage()];
    }
}

// =================== ФУНКЦИЯ ПОЛУЧЕНИЯ ЗАДАЧ ===================
function getPlanfixTasks($offset = 0, $limit = 100, $maxRetries = 5) {
    global $baseUrl, $apiToken;
    
    $tasksEndpoint = $baseUrl . 'task/list';
    
    $requestData = [
        "pageSize" => $limit,
        "offset" => $offset,
        "fields" => "id, name, description, additionalDescriptionData, priority, status, processId, resultChecking, type, assigner, parent, object, template, project, counterparty, dateTime, startDateTime, endDateTime, hasStartDate, hasEndDate, hasStartTime, hasEndTime, delayedTillDate, actualCompletionDate, dateOfLastUpdate, duration, durationUnit, durationType, overdue, closeToDeadLine, notAcceptedInTime, inFavorites, isSummary, isSequential, assignees, participants, auditors, recurrence, isDeleted, files, dataTags, sourceObjectId, sourceDataVersion"
    ];
    $attempt = 0;
    $lastError = null;
    
    while ($attempt < $maxRetries) {
        $attempt++;
        
        if ($attempt > 1) {
            logMessage("Попытка {$attempt} из {$maxRetries} для offset={$offset}", 'RETRY');
            
            // Экспоненциальная задержка: 60, 120, 240, 480 секунд и т.д.
            $delaySeconds = 60 * pow(2, $attempt - 2);
            logMessage("Ожидание {$delaySeconds} секунд перед повторной попыткой...", 'RETRY');
            sleep($delaySeconds);
        } else {
            logMessage("Запрос задач: offset={$offset}, limit={$limit}");
        }
        
        $result = makePlanfixRequest($tasksEndpoint, $apiToken, $requestData);
        
        if ($result['success']) {
            return $result;
        }
        
        $lastError = $result;
        
        // Определяем тип ошибки
        $httpCode = $result['http_code'] ?? 0;
        $errorMsg = $result['error'] ?? 'Неизвестная ошибка';
        
        // Логируем ошибку
        logMessage("Ошибка запроса (попытка {$attempt}): HTTP {$httpCode}, {$errorMsg}", 'ERROR');
        
        // Если это ошибка rate limiting или временная ошибка сервера, продолжаем попытки
        $retryableCodes = [408, 429, 500, 502, 503, 504, 403]; // Коды, при которых стоит повторить
        if (!in_array($httpCode, $retryableCodes) && $httpCode !== 0) {
            logMessage("Получен код {$httpCode}, который не требует повторных попыток. Прерывание.", 'ERROR');
            break;
        }
        
        // Проверяем наличие специфичных ошибок Planfix в ответе
        if (isset($result['data']['error']) || isset($result['data']['error_code'])) {
            $planfixError = $result['data']['error'] ?? $result['data']['error_code'] ?? '';
            logMessage("Ошибка Planfix: {$planfixError}", 'ERROR');
            
            // Некоторые ошибки Planfix могут быть временными
            if (strpos($planfixError, 'rate limit') !== false || 
                strpos($planfixError, 'too many requests') !== false) {
                logMessage("Обнаружено ограничение частоты запросов, увеличиваем задержку...", 'WARN');
                sleep(120); // Дополнительная задержка для rate limiting
            }
        }
    }
    
    logMessage("Все попытки ({$maxRetries}) исчерпаны для offset={$offset}. Операция прервана.", 'ERROR');
    return ['success' => false, 'error' => 'Исчерпаны все попытки', 'last_attempt' => $lastError];
}

// =================== ФУНКЦИЯ ДЛЯ РАБОТЫ СО СТАТУСОМ ===================
function loadDumpingStatus() {
    global $statusFile;
    
    if (file_exists($statusFile)) {
        $content = file_get_contents($statusFile);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }
    
    // Статус по умолчанию
    return [
        'last_offset' => 0,
        'files_created' => 0,
        'total_tasks_dumped' => 0,
        'started_at' => date('Y-m-d H:i:s'),
        'last_updated' => null,
        'current_partition' => 1
    ];
}

function saveDumpingStatus($status) {
    global $statusFile;
    
    $status['last_updated'] = date('Y-m-d H:i:s');
    
    $jsonData = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        logMessage("Ошибка кодирования статуса в JSON", 'ERROR');
        return false;
    }
    
    return file_put_contents($statusFile, $jsonData) !== false;
}

// =================== ФУНКЦИЯ СОХРАНЕНИЯ ПАРТИЦИИ ===================
function savePartition($tasks, $partitionNumber) {
    global $outputDirectory, $tasksPerPartition;
    
    // Создаем директорию, если не существует
    if (!file_exists($outputDirectory)) {
        if (!mkdir($outputDirectory, 0777, true)) {
            logMessage("Не удалось создать директорию: {$outputDirectory}", 'ERROR');
            return false;
        }
    }
    
    $filename = sprintf("tasks_part_%04d_%d-%d.json", 
        $partitionNumber, 
        ($partitionNumber - 1) * $tasksPerPartition + 1,
        $partitionNumber * $tasksPerPartition
    );
    
    $filepath = $outputDirectory . $filename;
    
    $data = [
        'metadata' => [
            'partition_number' => $partitionNumber,
            'tasks_count' => count($tasks),
            'created_at' => date('Y-m-d H:i:s'),
            'offset_start' => ($partitionNumber - 1) * $tasksPerPartition,
            'offset_end' => $partitionNumber * $tasksPerPartition - 1
        ],
        'tasks' => $tasks
    ];
    
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonData === false) {
        logMessage("Ошибка кодирования задач в JSON для партиции {$partitionNumber}", 'ERROR');
        return false;
    }
    
    $result = file_put_contents($filepath, $jsonData);
    
    if ($result === false) {
        logMessage("Не удалось сохранить файл: {$filepath}", 'ERROR');
        return false;
    }
    
    logMessage("Сохранена партиция {$partitionNumber}: {$filename} (" . count($tasks) . " задач)");
    return true;
}
// =================== ОСНОВНАЯ ФУНКЦИЯ ===================
function dumpTasks() {
    global $tasksPerPartition, $outputDirectory;
    
    // Загружаем статус
    $status = loadDumpingStatus();
    
    logMessage("=" . str_repeat("=", 60));
    logMessage("НАЧАЛО СОХРАНЕНИЯ ЗАДАЧ ИЗ PLANFIX");
    logMessage("Текущий статус:");
    logMessage("  Последнее смещение: " . $status['last_offset']);
    logMessage("  Создано файлов: " . $status['files_created']);
    logMessage("  Всего задач сохранено: " . $status['total_tasks_dumped']);
    logMessage("  Текущая партиция: " . $status['current_partition']);
    logMessage("=" . str_repeat("=", 60));
    
    // Начинаем с текущего смещения
    $currentOffset = $status['last_offset'];
    $currentPartition = $status['current_partition'];
    $tasksInCurrentPartition = [];
    $batchSize = 100;
    $totalTasksDumped = $status['total_tasks_dumped'];
    $filesCreated = $status['files_created'];
    
    $maxRequests = 7000;
    $requestCount = 0;
    $consecutiveErrors = 0;
    $maxConsecutiveErrors = 3;
    $saveCounter = 0; // Счетчик для сохранения каждые 10 запросов
    
    try {
        while ($requestCount < $maxRequests) {
            $requestCount++;
            $saveCounter++;
            
            // Получаем задачи с повторными попытками
            $result = getPlanfixTasks($currentOffset, $batchSize);
            
            if (!$result['success']) {
                $consecutiveErrors++;
                logMessage("Ошибка при получении задач. Последовательных ошибок: {$consecutiveErrors}/{$maxConsecutiveErrors}", 'ERROR');
                
                if ($consecutiveErrors >= $maxConsecutiveErrors) {
                    logMessage("Достигнуто максимальное количество последовательных ошибок. Прерывание.", 'ERROR');
                    break;
                }
                
                // Сохраняем статус перед длительной паузой
                $status['last_offset'] = $currentOffset;
                $status['files_created'] = $filesCreated;
                $status['total_tasks_dumped'] = $totalTasksDumped;
                $status['current_partition'] = $currentPartition;
                saveDumpingStatus($status);
                
                logMessage("Ожидание 2 минут перед следующей попыткой...", 'RETRY');
                sleep(120);
                continue;
            }
            
            // Сброс счетчика ошибок при успешном запросе
            $consecutiveErrors = 0;
            
            $tasks = $result['data']['tasks'] ?? [];
            $tasksCount = count($tasks);
            
            if ($tasksCount === 0) {
                logMessage("Получено 0 задач. Возможно, достигнут конец списка.");
                
                // Сохраняем оставшиеся задачи в текущей партиции
                if (!empty($tasksInCurrentPartition)) {
                    if (savePartition($tasksInCurrentPartition, $currentPartition)) {
                        $filesCreated++;
                        $totalTasksDumped += count($tasksInCurrentPartition);
                        $tasksInCurrentPartition = [];
                    }
                }
                
                logMessage("Достигнут конец списка задач. Завершение.");
                break;
            }
            
            logMessage("Получено {$tasksCount} задач, offset={$currentOffset}");
            
            // Добавляем задачи в текущую партицию
            $tasksInCurrentPartition = array_merge($tasksInCurrentPartition, $tasks);
            
            // Проверяем, заполнена ли текущая партиция
            if (count($tasksInCurrentPartition) >= $tasksPerPartition) {
                if (savePartition(array_slice($tasksInCurrentPartition, 0, $tasksPerPartition), $currentPartition)) {
                    $filesCreated++;
                    $totalTasksDumped += $tasksPerPartition;
                    
                    $tasksInCurrentPartition = array_slice($tasksInCurrentPartition, $tasksPerPartition);
                    $currentPartition++;
                    
                    logMessage("Переход к партиции {$currentPartition}");
                }
            }
            
            // Обновляем смещение
            $currentOffset += $tasksCount;
            
            // СОХРАНЕНИЕ КАЖДЫЕ 10 ЗАПРОСОВ
            if ($saveCounter >= 10) {
                logMessage("Выполнено 10 запросов. Сохраняем промежуточный результат...");
                
                // Сохраняем текущие задачи, если они есть
                if (!empty($tasksInCurrentPartition)) {
                    if (savePartition($tasksInCurrentPartition, $currentPartition)) {
                        $filesCreated++;
                        $totalTasksDumped += count($tasksInCurrentPartition);
                        $tasksInCurrentPartition = [];
                        $currentPartition++;
                        
                        logMessage("Промежуточное сохранение завершено. Новая партиция: {$currentPartition}");
                    }
                }
                
                // Сбрасываем счетчик
                $saveCounter = 0;
            }
            
            // Обновляем статус каждые 10 запросов (теперь это дублируется с сохранением, но оставим для совместимости)
            if ($requestCount % 10 === 0) {
                $status['last_offset'] = $currentOffset;
                $status['files_created'] = $filesCreated;
                $status['total_tasks_dumped'] = $totalTasksDumped;
                $status['current_partition'] = $currentPartition;
                saveDumpingStatus($status);
                
                logMessage("Промежуточный статус: offset={$currentOffset}, партиций={$filesCreated}, задач={$totalTasksDumped}");
            }
            
            // Пауза между запросами
            sleep(1);
            
            // Проверка памяти
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            if ($memoryUsage > 512) {
                logMessage("Использование памяти: {$memoryUsage} MB. Очистка памяти.");
                if (!empty($tasksInCurrentPartition)) {
                    if (savePartition($tasksInCurrentPartition, $currentPartition)) {
                        $filesCreated++;
                        $totalTasksDumped += count($tasksInCurrentPartition);
                        $tasksInCurrentPartition = [];
                        $currentPartition++;
                    }
                }
                gc_collect_cycles();
            }
        }
        
        // Сохраняем последнюю партицию
        if (!empty($tasksInCurrentPartition)) {
            if (savePartition($tasksInCurrentPartition, $currentPartition)) {
                $filesCreated++;
                $totalTasksDumped += count($tasksInCurrentPartition);
            }
        }
        
        // Финальное обновление статуса
        $status['last_offset'] = $currentOffset;
        $status['files_created'] = $filesCreated;
        $status['total_tasks_dumped'] = $totalTasksDumped;
        $status['current_partition'] = $currentPartition;
        saveDumpingStatus($status);
        
        logMessage("=" . str_repeat("=", 60));
        logMessage("СОХРАНЕНИЕ ЗАДАЧ ЗАВЕРШЕНО");
        logMessage("ИТОГИ:");
        logMessage("  Всего создано партиций: {$filesCreated}");
        logMessage("  Всего сохранено задач: {$totalTasksDumped}");
        logMessage("  Последнее смещение: {$currentOffset}");
        logMessage("=" . str_repeat("=", 60));
        
        return true;
        
    } catch (Exception $e) {
        logMessage("Критическая ошибка: " . $e->getMessage(), 'ERROR');
        
        $status['last_offset'] = $currentOffset;
        $status['files_created'] = $filesCreated;
        $status['total_tasks_dumped'] = $totalTasksDumped;
        $status['current_partition'] = $currentPartition;
        saveDumpingStatus($status);
        
        return false;
    }
}
// =================== ФУНКЦИЯ ДЛЯ ЧТЕНИЯ ПАРТИЦИИ ===================
function readPartition($partitionNumber) {
    global $outputDirectory;
    
    $pattern = $outputDirectory . "tasks_part_" . sprintf("%0d", $partitionNumber) . "_*.json";
    $files = glob($pattern);
    
    if (empty($files)) {
        logMessage("Файл партиции {$partitionNumber} не найден", 'ERROR');
        return null;
    }
    
    $filepath = $files[0];
    $content = file_get_contents($filepath);
    
    if ($content === false) {
        logMessage("Не удалось прочитать файл: {$filepath}", 'ERROR');
        return null;
    }
    
    $data = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("Ошибка декодирования JSON из файла {$filepath}", 'ERROR');
        return null;
    }
    
    return $data;
}

// =================== ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ ИНФОРМАЦИИ О СОХРАНЕННЫХ ФАЙЛАХ ===================
function getDumpedFilesInfo() {
    global $outputDirectory;
    
    if (!file_exists($outputDirectory)) {
        return [];
    }
    
    $files = glob($outputDirectory . "tasks_part_*.json");
    $info = [];
    
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $filesize = filesize($filepath);
        $filetime = filemtime($filepath);
        
        // Извлекаем номер партиции из имени файла
        if (preg_match('/tasks_part_(\d+)_/', $filename, $matches)) {
            $partitionNumber = (int)$matches[1];
            
            // Читаем метаданные из файла
            $content = file_get_contents($filepath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (isset($data['metadata'])) {
                    $info[] = [
                        'partition' => $partitionNumber,
                        'filename' => $filename,
                        'size_mb' => round($filesize / 1024 / 1024, 2),
                        'tasks_count' => $data['metadata']['tasks_count'] ?? 0,
                        'created_at' => $data['metadata']['created_at'] ?? date('Y-m-d', $filetime),
                        'offset_start' => $data['metadata']['offset_start'] ?? 0,
                        'offset_end' => $data['metadata']['offset_end'] ?? 0
                    ];
                }
            }
        }
    }
    
    // Сортируем по номеру партиции
    usort($info, function($a, $b) {
        return $a['partition'] <=> $b['partition'];
    });
    
    return $info;
}

// =================== ОСНОВНОЙ СКРИПТ ===================
try {
    // Создаем обработчик ошибок
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        logMessage("PHP Error: {$errstr} in {$errfile}:{$errline}", 'ERROR');
        return true;
    });
    
    set_exception_handler(function($e) {
        logMessage("Uncaught Exception: " . $e->getMessage(), 'ERROR');
    });
    
    // Проверяем аргументы командной строки
    $action = 'dump'; // По умолчанию - дамп задач
    
    if (PHP_SAPI === 'cli' && isset($argv[1])) {
        $action = $argv[1];
    }
    
    switch ($action) {
        case 'dump':
            logMessage("Запуск в режиме сохранения задач...");
            dumpTasks();
            break;
            
        case 'info':
            logMessage("Информация о сохраненных файлах:");
            $filesInfo = getDumpedFilesInfo();
            
            if (empty($filesInfo)) {
                logMessage("Нет сохраненных файлов.");
            } else {
                $totalTasks = 0;
                $totalSize = 0;
                
                foreach ($filesInfo as $file) {
                    logMessage("  Партиция {$file['partition']}: {$file['filename']}");
                    logMessage("    Задачи: {$file['tasks_count']}, Размер: {$file['size_mb']} MB");
                    logMessage("    Смещение: {$file['offset_start']} - {$file['offset_end']}");
                    logMessage("    Создан: {$file['created_at']}");
                    logMessage("");
                    
                    $totalTasks += $file['tasks_count'];
                    $totalSize += $file['size_mb'];
                }
                
                logMessage("ИТОГО: " . count($filesInfo) . " файлов, {$totalTasks} задач, " . round($totalSize, 2) . " MB");
            }
            break;
            
        case 'status':
            $status = loadDumpingStatus();
            logMessage("Текущий статус:");
            echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            break;
            
        case 'reset':
            if (file_exists($statusFile)) {
                unlink($statusFile);
                logMessage("Файл статуса удален. Можно начать заново.");
            } else {
                logMessage("Файл статуса не найден.");
            }
            break;
            
        default:
            logMessage("Доступные команды:");
            logMessage("  php task_dumper.php dump    - сохранение задач");
            logMessage("  php task_dumper.php info    - информация о файлах");
            logMessage("  php task_dumper.php status  - текущий статус");
            logMessage("  php task_dumper.php reset   - сброс статуса");
            break;
    }
    
} catch (Exception $e) {
    logMessage("Критическая ошибка в основном скрипте: " . $e->getMessage(), 'ERROR');
    exit(1);
}