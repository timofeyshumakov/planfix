<?php
// checklist_fetcher.php
// Скрипт для получения чек-листов задач из Planfix по ID из файла

// =================== КОНФИГУРАЦИЯ ===================
require_once dirname(__DIR__) . '/crest.php';
$baseUrl = rtrim($_ENV['PLANFIX_BASE_URL'] ?? getenv('PLANFIX_BASE_URL') ?: '', '/') . '/';
$apiToken = $_ENV['PLANFIX_API_TOKEN'] ?? getenv('PLANFIX_API_TOKEN') ?: '';
if ($baseUrl === '/' || $apiToken === '') {
    fwrite(STDERR, "Set PLANFIX_BASE_URL and PLANFIX_API_TOKEN in .env\n");
    exit(1);
}
$taskIdsFile = 'task_ids.txt'; // Файл с ID задач
$outputDirectory = __DIR__ . '/checklists/'; // Директория для сохранения
$statusFile = __DIR__ . '/checklist_status.json'; // Файл статуса
$batchSize = 50; // Уменьшили размер батча для снижения нагрузки на память
$maxMemoryLimit = 1024 * 1024 * 1024; // 1024 MB - максимальный лимит памяти
$partitionSize = 2500; // Уменьшили размер партиции для частого сохранения

// =================== ЛОГИРОВАНИЕ ===================
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$type}] {$message}\n";
    
    // Также сохраняем в лог файл
    file_put_contents(__DIR__ . '/checklist_fetch_log.txt', "[{$timestamp}] [{$type}] {$message}\n", FILE_APPEND);
}

// =================== ФУНКЦИИ API ===================
/**
 * Выполняет запрос к Planfix API
 */
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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
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

/**
 * Получает чек-лист задачи из Planfix с ограничением глубины рекурсии
 */
function getPlanfixTaskChecklist($taskId) {
    global $baseUrl, $apiToken;
    
    try {
        $checklistEndpoint = $baseUrl . 'task/' . $taskId . '/checklist/list';
        
        $data = [
            "offset" => 0,
            "pageSize" => 50, // Уменьшили для снижения нагрузки
            "fields" => "id,name,parent,isDone,hasChildren"
        ];

        logMessage("Запрос чек-листа для задачи: {$taskId}");
        
        $checklistResult = makePlanfixRequest($checklistEndpoint, $apiToken, $data);

        if (!$checklistResult['success']) {
            logMessage("Ошибка получения чек-листа для задачи {$taskId}", 'ERROR');
            return null;
        }
        
        $checklist = $checklistResult['data'] ?? [];
        
        // Ограничиваем глубину рекурсии и размер данных
        if (isset($checklist['items']) && is_array($checklist['items'])) {
            $processedItems = processChecklistItemsLimited($checklist['items'], $taskId);
            $checklist['items'] = $processedItems;
        }
        
        // Упрощаем структуру для экономии памяти
        return [
            'task_id' => $taskId,
            'checklist' => $checklist,
            'fetched_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        logMessage("Исключение при получении чек-листа: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * Обрабатывает элементы чек-листа с ограничением глубины рекурсии
 */
function processChecklistItemsLimited($items, $taskId, $depth = 0, $maxDepth = 3) {
    global $baseUrl, $apiToken;
    
    // Ограничиваем глубину рекурсии
    if ($depth >= $maxDepth) {
        logMessage("Достигнута максимальная глубина рекурсии ({$maxDepth}) для задачи {$taskId}", 'WARNING');
        return $items;
    }
    
    $processed = [];
    $itemsToProcess = min(count($items), 20); // Ограничиваем количество элементов
    
    for ($i = 0; $i < $itemsToProcess; $i++) {
        $item = $items[$i];
        try {
            $itemData = [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? '',
                'isDone' => $item['isDone'] ?? false
            ];
            
            // Получаем детей только если есть необходимость и мы не превысили лимит
            if (isset($item['hasChildren']) && $item['hasChildren'] && !empty($item['id']) && $depth < $maxDepth - 1) {
                $childrenEndpoint = $baseUrl . 'task/' . $taskId . '/checklist/' . $item['id'] . '/list';
                
                $childrenData = [
                    "offset" => 0,
                    "pageSize" => 20, // Ограничиваем количество детей
                    "fields" => "id,name,parent,isDone,hasChildren"
                ];
                
                $childrenResult = makePlanfixRequest($childrenEndpoint, $apiToken, $childrenData);
                
                if ($childrenResult['success'] && isset($childrenResult['data']['items'])) {
                    $children = processChecklistItemsLimited($childrenResult['data']['items'], $taskId, $depth + 1, $maxDepth);
                    $itemData['children'] = $children;
                }
            }
            
            $processed[] = $itemData;
            
        } catch (Exception $e) {
            logMessage("Ошибка обработки элемента чек-листа: " . $e->getMessage(), 'WARNING');
            // Сохраняем минимальные данные при ошибке
            $processed[] = [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? '',
                'isDone' => $item['isDone'] ?? false,
                'error' => 'processing_error'
            ];
        }
    }
    
    return $processed;
}

// =================== ФУНКЦИИ ДЛЯ РАБОТЫ С ФАЙЛАМИ ===================

/**
 * Загружает ID задач из файла
 */
function loadTaskIdsFromFile($filename) {
    try {
        if (!file_exists($filename)) {
            logMessage("Файл с ID задач не найден: {$filename}", 'ERROR');
            return [];
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            logMessage("Не удалось прочитать файл: {$filename}", 'ERROR');
            return [];
        }
        
        // Разбиваем по строкам и фильтруем пустые значения
        $ids = explode("\n", $content);
        $ids = array_map('trim', $ids);
        $ids = array_filter($ids, function($id) {
            return !empty($id) && is_numeric($id);
        });
        
        $ids = array_values($ids); // Сбрасываем ключи
        
        logMessage("Загружено ID задач из файла: " . count($ids));
        
        return $ids;
        
    } catch (Exception $e) {
        logMessage("Исключение при загрузке ID задач: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Загружает статус выполнения
 */
function loadChecklistStatus() {
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
        'last_processed_index' => 0,
        'total_processed' => 0,
        'total_successful' => 0,
        'total_failed' => 0,
        'started_at' => date('Y-m-d H:i:s'),
        'last_updated' => null,
        'current_partition' => 1,
        'memory_peak' => 0
    ];
}

/**
 * Сохраняет статус выполнения
 */
function saveChecklistStatus($status) {
    global $statusFile;
    
    $status['last_updated'] = date('Y-m-d H:i:s');
    $status['memory_peak'] = max($status['memory_peak'] ?? 0, memory_get_peak_usage(true));
    
    $jsonData = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        logMessage("Ошибка кодирования статуса в JSON", 'ERROR');
        return false;
    }
    
    return file_put_contents($statusFile, $jsonData) !== false;
}

/**
 * Сохраняет чек-листы в файл (добавляет к существующему)
 */
function saveChecklistsToPartition($checklists, $partitionNumber) {
    global $outputDirectory;
    
    // Создаем директорию, если не существует
    if (!file_exists($outputDirectory)) {
        if (!mkdir($outputDirectory, 0777, true)) {
            logMessage("Не удалось создать директорию: {$outputDirectory}", 'ERROR');
            return false;
        }
    }
    
    $filename = sprintf("checklists_part_%04d.json", $partitionNumber);
    $filepath = $outputDirectory . $filename;
    
    $dataToSave = [];
    
    // Если файл уже существует, загружаем существующие данные
    if (file_exists($filepath)) {
        $existingContent = file_get_contents($filepath);
        if ($existingContent !== false) {
            $existingData = json_decode($existingContent, true);
            if ($existingData !== null && isset($existingData['checklists'])) {
                $dataToSave = $existingData['checklists'];
            }
        }
    }
    
    // Добавляем новые данные
    $dataToSave = array_merge($dataToSave, $checklists);
    
    // Формируем полные данные
    $fullData = [
        'metadata' => [
            'checklists_count' => count($dataToSave),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'partition' => $partitionNumber
        ],
        'checklists' => $dataToSave
    ];
    
    $jsonData = json_encode($fullData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonData === false) {
        logMessage("Ошибка кодирования чек-листов в JSON", 'ERROR');
        return false;
    }
    
    // Сохраняем во временный файл, затем переименовываем
    $tempFile = $filepath . '.tmp';
    $result = file_put_contents($tempFile, $jsonData);
    
    if ($result === false) {
        logMessage("Не удалось сохранить временный файл: {$tempFile}", 'ERROR');
        return false;
    }
    
    // Атомарная замена файла
    if (!rename($tempFile, $filepath)) {
        logMessage("Не удалось переименовать временный файл в {$filepath}", 'ERROR');
        unlink($tempFile);
        return false;
    }
    
    logMessage("Сохранено в партицию {$partitionNumber}: " . count($checklists) . " чек-листов (всего: " . count($dataToSave) . ")");
    return true;
}

/**
 * Обрабатывает батч задач и сразу сохраняет результаты
 */
function processAndSaveBatch($taskIds, $startIndex, $batchSize, $partitionNumber) {
    global $partitionSize;
    
    $checklists = [];
    $processedCount = 0;
    $successfulCount = 0;
    $failedCount = 0;
    
    $endIndex = min($startIndex + $batchSize, count($taskIds));
    
    logMessage("Обработка батча: задачи {$startIndex}-{$endIndex}");
    
    for ($i = $startIndex; $i < $endIndex; $i++) {
        $taskId = $taskIds[$i];
        $processedCount++;
        
        try {
            logMessage("  Задача {$i}: ID={$taskId}");
            
            $checklistData = getPlanfixTaskChecklist($taskId);
            
            if ($checklistData !== null) {
                $checklists[] = $checklistData;
                $successfulCount++;
                logMessage("    ✓ Успешно получен чек-лист");
            } else {
                $failedCount++;
                logMessage("    ✗ Не удалось получить чек-лист");
                
                // Минимальные данные об ошибке
                $checklists[] = [
                    'task_id' => $taskId,
                    'error' => 'fetch_failed',
                    'fetched_at' => date('Y-m-d H:i:s')
                ];
            }
            
            // Проверяем использование памяти и сохраняем при необходимости
            $memoryUsage = memory_get_usage(true);
            if ($memoryUsage > 512 * 1024 * 1024) { // 512 MB
                logMessage("Использование памяти высокое ({$memoryUsage} байт), сохраняем и очищаем...");
                
                if (!empty($checklists)) {
                    saveChecklistsToPartition($checklists, $partitionNumber);
                    $checklists = []; // Освобождаем память
                    gc_collect_cycles(); // Принудительная сборка мусора
                }
            }
            
            // Небольшая пауза между запросами
            if ($i < $endIndex - 1) {
                usleep(100000); // 100ms пауза
            }
            
        } catch (Exception $e) {
            $failedCount++;
            logMessage("    ✗ Исключение: " . $e->getMessage(), 'ERROR');
            
            $checklists[] = [
                'task_id' => $taskId,
                'error' => 'exception: ' . substr($e->getMessage(), 0, 100),
                'fetched_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    // Сохраняем результаты батча
    if (!empty($checklists)) {
        saveChecklistsToPartition($checklists, $partitionNumber);
    }
    
    return [
        'processed' => $processedCount,
        'successful' => $successfulCount,
        'failed' => $failedCount,
        'checklists_saved' => count($checklists)
    ];
}

// =================== ОСНОВНАЯ ФУНКЦИЯ ===================
function fetchAllChecklists() {
    global $taskIdsFile, $outputDirectory, $batchSize, $partitionSize, $maxMemoryLimit;
    
    // Устанавливаем лимит памяти
    ini_set('memory_limit', $maxMemoryLimit);
    
    // Загружаем ID задач
    $taskIds = loadTaskIdsFromFile($taskIdsFile);
    
    if (empty($taskIds)) {
        logMessage("Нет ID задач для обработки", 'ERROR');
        return false;
    }
    
    logMessage("Всего ID задач для обработки: " . count($taskIds));
    
    // Загружаем статус
    $status = loadChecklistStatus();
    $currentIndex = $status['last_processed_index'];
    $currentPartition = $status['current_partition'];
    
    logMessage("=" . str_repeat("=", 60));
    logMessage("НАЧАЛО ПОЛУЧЕНИЯ ЧЕК-ЛИСТОВ ИЗ PLANFIX");
    logMessage("Текущий статус:");
    logMessage("  Последний обработанный индекс: {$currentIndex}");
    logMessage("  Всего обработано: {$status['total_processed']}");
    logMessage("  Успешно: {$status['total_successful']}, Неудачно: {$status['total_failed']}");
    logMessage("  Текущая партиция: {$currentPartition}");
    logMessage("  Пиковое использование памяти: " . round(($status['memory_peak'] ?? 0) / 1024 / 1024, 2) . " MB");
    logMessage("=" . str_repeat("=", 60));
    
    $totalTasks = count($taskIds);
    $iterations = 0;
    $maxIterations = ceil(($totalTasks - $currentIndex) / $batchSize);
    
    try {
        while ($currentIndex < $totalTasks && $iterations < $maxIterations * 2) { // Защита от бесконечного цикла
            $iterations++;
            
            logMessage("Итерация {$iterations}: текущий индекс {$currentIndex}/{$totalTasks}");
            logMessage("Использование памяти: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB");
            
            // Обрабатываем батч и сразу сохраняем
            $result = processAndSaveBatch($taskIds, $currentIndex, $batchSize, $currentPartition);
            
            // Обновляем индексы и статистику
            $currentIndex += $result['processed'];
            
            $status['last_processed_index'] = $currentIndex;
            $status['total_processed'] += $result['processed'];
            $status['total_successful'] += $result['successful'];
            $status['total_failed'] += $result['failed'];
            
            // Проверяем, не нужно ли перейти к следующей партиции
            $partitionFile = $outputDirectory . sprintf("checklists_part_%04d.json", $currentPartition);
            if (file_exists($partitionFile)) {
                $content = file_get_contents($partitionFile);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if ($data !== null && isset($data['metadata']['checklists_count']) && 
                        $data['metadata']['checklists_count'] >= $partitionSize) {
                        $currentPartition++;
                        $status['current_partition'] = $currentPartition;
                        logMessage("Переход к новой партиции: {$currentPartition}");
                    }
                }
            }
            
            // Сохраняем статус каждые 5 итераций
            if ($iterations % 5 === 0) {
                saveChecklistStatus($status);
                logMessage("Промежуточный статус сохранен. Прогресс: " . 
                          round(($currentIndex / $totalTasks) * 100, 2) . "%");
            }
            
            // Очистка памяти между итерациями
            gc_collect_cycles();
            
            // Пауза между итерациями
            if ($currentIndex < $totalTasks) {
                $pauseTime = 2; // 2 секунды
                logMessage("Пауза {$pauseTime} секунд...");
                sleep($pauseTime);
            }
            
            // Защита от переполнения памяти
            if (memory_get_usage(true) > $maxMemoryLimit * 0.8) { // 80% от лимита
                logMessage("Критическое использование памяти, принудительная очистка...", 'WARNING');
                gc_collect_cycles();
                logMessage("Использование памяти после очистки: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB");
            }
        }
        
        // Финальное сохранение статуса
        $status['last_updated'] = date('Y-m-d H:i:s');
        $status['memory_peak'] = memory_get_peak_usage(true);
        saveChecklistStatus($status);
        
        logMessage("=" . str_repeat("=", 60));
        logMessage("ПОЛУЧЕНИЕ ЧЕК-ЛИСТОВ ЗАВЕРШЕНО");
        logMessage("ИТОГИ:");
        logMessage("  Всего обработано задач: {$status['total_processed']}");
        logMessage("  Успешно получено чек-листов: {$status['total_successful']}");
        logMessage("  Не удалось получить: {$status['total_failed']}");
        logMessage("  Создано партиций: {$status['current_partition']}");
        logMessage("  Пиковое использование памяти: " . round($status['memory_peak'] / 1024 / 1024, 2) . " MB");
        logMessage("  Файлы сохранены в: {$outputDirectory}");
        logMessage("=" . str_repeat("=", 60));
        
        return true;
        
    } catch (Exception $e) {
        logMessage("Критическая ошибка: " . $e->getMessage(), 'ERROR');
        
        // Сохраняем текущий статус при ошибке
        $status['last_processed_index'] = $currentIndex;
        $status['current_partition'] = $currentPartition;
        $status['memory_peak'] = memory_get_peak_usage(true);
        saveChecklistStatus($status);
        
        return false;
    }
}

// =================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===================
/**
 * Получает информацию о сохраненных партициях
 */
function getPartitionsInfo() {
    global $outputDirectory;
    
    if (!file_exists($outputDirectory)) {
        return [];
    }
    
    $files = glob($outputDirectory . "checklists_part_*.json");
    $info = [];
    
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $filesize = filesize($filepath);
        $filetime = filemtime($filepath);
        
        if (preg_match('/checklists_part_(\d+)\.json/', $filename, $matches)) {
            $partitionNumber = (int)$matches[1];
            
            $content = file_get_contents($filepath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if ($data !== null && isset($data['metadata'])) {
                    $info[] = [
                        'partition' => $partitionNumber,
                        'filename' => $filename,
                        'size_mb' => round($filesize / 1024 / 1024, 2),
                        'checklists_count' => $data['metadata']['checklists_count'] ?? 0,
                        'created_at' => $data['metadata']['created_at'] ?? date('Y-m-d', $filetime),
                        'updated_at' => $data['metadata']['updated_at'] ?? date('Y-m-d', $filetime)
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

// =================== ТОЧКА ВХОДА ===================
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
    $action = 'fetch'; // По умолчанию - получение чек-листов
    
    if (PHP_SAPI === 'cli' && isset($argv[1])) {
        $action = $argv[1];
    }
    
    switch ($action) {
        case 'fetch':
            logMessage("Запуск в режиме получения чек-листов...");
            fetchAllChecklists();
            break;
            
        case 'status':
            $status = loadChecklistStatus();
            logMessage("Текущий статус:");
            echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            break;
            
        case 'info':
            logMessage("Информация о сохраненных партициях:");
            $partitionsInfo = getPartitionsInfo();
            
            if (empty($partitionsInfo)) {
                logMessage("Нет сохраненных партиций.");
            } else {
                $totalChecklists = 0;
                $totalSize = 0;
                
                foreach ($partitionsInfo as $partition) {
                    logMessage("  Партиция {$partition['partition']}: {$partition['filename']}");
                    logMessage("    Чек-листов: {$partition['checklists_count']}, Размер: {$partition['size_mb']} MB");
                    logMessage("    Создан: {$partition['created_at']}, Обновлен: {$partition['updated_at']}");
                    logMessage("");
                    
                    $totalChecklists += $partition['checklists_count'];
                    $totalSize += $partition['size_mb'];
                }
                
                logMessage("ИТОГО: " . count($partitionsInfo) . " партиций, {$totalChecklists} чек-листов, " . 
                          round($totalSize, 2) . " MB");
            }
            break;
            
        case 'reset':
            if (file_exists($statusFile)) {
                unlink($statusFile);
                logMessage("Файл статуса удален. Можно начать заново.");
            } else {
                logMessage("Файл статуса не найден.");
            }
            break;
            
        case 'test':
            // Тестовый запрос для одной задачи
            if (isset($argv[2]) && is_numeric($argv[2])) {
                $testTaskId = $argv[2];
                logMessage("Тестовый запрос чек-листа для задачи: {$testTaskId}");
                
                $checklist = getPlanfixTaskChecklist($testTaskId);
                
                if ($checklist) {
                    echo json_encode($checklist, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                    logMessage("Тестовый запрос выполнен успешно");
                } else {
                    logMessage("Тестовый запрос не удался");
                }
            } else {
                logMessage("Укажите ID задачи для теста: php checklist_fetcher.php test 12345");
            }
            break;
            
        default:
            logMessage("Доступные команды:");
            logMessage("  php checklist_fetcher.php fetch    - получение чек-листов");
            logMessage("  php checklist_fetcher.php status   - текущий статус");
            logMessage("  php checklist_fetcher.php info     - информация о партициях");
            logMessage("  php checklist_fetcher.php reset    - сброс статуса");
            logMessage("  php checklist_fetcher.php test ID  - тестовый запрос для одной задачи");
            logMessage("");
            logMessage("Оптимизации для работы с большими объемами:");
            logMessage("  1. Постоянное сохранение данных на диск");
            logMessage("  2. Ограничение глубины рекурсии");
            logMessage("  3. Контроль использования памяти");
            logMessage("  4. Принудительная сборка мусора");
            break;
    }
    
} catch (Exception $e) {
    logMessage("Критическая ошибка в основном скрипте: " . $e->getMessage(), 'ERROR');
    exit(1);
}