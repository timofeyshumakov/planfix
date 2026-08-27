<?php
// файл: import_checklists.php
require_once dirname(__DIR__) . '/crest.php';

// =================== КОНФИГУРАЦИЯ ===================
$baseUrl = rtrim($_ENV['PLANFIX_BASE_URL'] ?? getenv('PLANFIX_BASE_URL') ?: '', '/') . '/';
$apiToken = $_ENV['PLANFIX_API_TOKEN'] ?? getenv('PLANFIX_API_TOKEN') ?: '';
if ($baseUrl === '/' || $apiToken === '') {
    fwrite(STDERR, "Set PLANFIX_BASE_URL and PLANFIX_API_TOKEN in .env\n");
    exit(1);
}

// =================== ФУНКЦИИ ===================

/**
 * Функция для выполнения API-запросов к Planfix
 */
/**
 * Функция для выполнения API-запросов к Planfix
 */
function makePlanfixRequest($url, $apiToken, $data = null)
{
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
    ];

    if ($data !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'Ошибка cURL: ' . $curlError];
    }

    // Логируем ответ для отладки
    file_put_contents(__DIR__ . '/planfix_debug.log', 
        "[" . date('Y-m-d H:i:s') . "] URL: $url\n" .
        "Response: $response\n" .
        "HTTP Code: $httpCode\n" .
        str_repeat("-", 50) . "\n", 
        FILE_APPEND
    );

    // Пробуем декодировать JSON
    $result = json_decode($response, true);

    // Если декодирование не удалось, проверяем, может быть ответ пустой
    if ($result === null && $response !== 'null' && !empty($response)) {
        // Ответ не является валидным JSON
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'Invalid JSON response',
            'response' => $response
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true, 
            'http_code' => $httpCode,
            'data' => $result ?? [],
            'response' => $response
        ];
    } else {
        return [
            'success' => false, 
            'http_code' => $httpCode, 
            'data' => $result ?? [],
            'response' => $response,
            'error' => isset($result['error']) ? $result['error'] : 'HTTP Error ' . $httpCode
        ];
    }
}

/**
 * Получает список всех активных задач из Битрикс с Planfix ID
 */
function getActiveBitrixTasksWithPlanfixId($limit = 100)
{
    $tasks = [];
    $start = 0;
    $batchSize = 50;
    
    echo "Получаем активные задачи из Битрикс...\n";
    
    while (true) {
        $result = CRest::call('tasks.task.list', [
            'filter' => [
                '!UF_AUTO_127801401239' => false,
                '!STATUS' => 5 // Исключаем завершенные задачи (статус 5)
            ],
            'select' => ['ID', 'TITLE', 'STATUS', 'UF_AUTO_127801401239'],
            'order' => ['ID' => 'DESC'],
            'start' => $start
        ]);
        
        if (isset($result['error'])) {
            echo "Ошибка получения задач: " . $result['error_description'] . "\n";
            return [];
        }
        
        if (empty($result['result']['tasks'])) {
            echo "Нет активных задач с Planfix ID\n";
            break;
        }
        
        $batchTasks = $result['result']['tasks'];
        $tasks = array_merge($tasks, $batchTasks);
        
        echo "Получено: " . count($batchTasks) . " задач (всего: " . count($tasks) . ")\n";
        
        // Ограничиваем общее количество
        if (count($tasks) >= $limit) {
            echo "Достигнут лимит задач: {$limit}\n";
            $tasks = array_slice($tasks, 0, $limit);
            break;
        }
        
        // Если получено меньше, чем запрошено, значит это последняя страница
        if (count($batchTasks) < $batchSize) {
            break;
        }
        
        $start += $batchSize;
        sleep(0.2); // Пауза между запросами
    }
    
    return $tasks;
}

/**
 * Получает чек-листы задачи из Planfix
 */
function getPlanfixTaskChecklists($planfixTaskId)
{
    global $baseUrl, $apiToken;
    $tasksRequestData = [
        "pageSize" => 100,
        "offset" => 0,
        "fields" => "id,name,parent,isDone",
    ];
    $endpoint = $baseUrl . 'task/' . $planfixTaskId . '/checklist/list';
    
    echo "  Получение чек-листов для задачи Planfix ID: {$planfixTaskId}\n";
    $result = makePlanfixRequest($endpoint, $apiToken, $tasksRequestData);

    if (!$result['data']['result']) {
        echo "  ✗ Ошибка получения чек-листов: HTTP код " . 
             ($result['http_code'] ?? 'N/A') . "\n";
        return [];
    }
    
    $checklists = $result['data']['items'] ?? [];
    
    if (empty($checklists)) {
        echo "  ✓ Чек-листов нет\n";
        return [];
    }
    
    echo "  ✓ Найдено чек-листов: " . count($checklists) . "\n";
    return $checklists;
}

/**
 * Создает чек-лист в задаче Битрикс
 */
function createBitrixChecklist($bitrixTaskId, $checklistTitle, $items = [], $isDone)
{
    // Сначала создаем чек-лист
    $result = CRest::call('task.checklistitem.add', [
        'TASKID' => $bitrixTaskId,
        'FIELDS' => [
            'TITLE' => $checklistTitle,
            'IS_COMPLETE' => $isDone,
            'SORT_INDEX' => 100
        ]
    ]);
    
    if (isset($result['error'])) {
        echo "    ✗ Ошибка создания чек-листа '{$checklistTitle}': " . 
             $result['error_description'] . "\n";
        return null;
    }
    
    $checklistId = $result['result'];
    echo "    ✓ Создан чек-лист: {$checklistTitle} (ID: {$checklistId})\n";
    
    // Добавляем элементы в чек-лист
    if (!empty($items)) {
        foreach ($items as $index => $item) {
            $itemResult = CRest::call('task.checklist.additem', [
                'taskId' => $bitrixTaskId,
                'checklistId' => $checklistId,
                'fields' => [
                    'TITLE' => $item['title'],
                    'IS_COMPLETE' => $item['is_completed'] ? 'Y' : 'N',
                    'SORT_INDEX' => ($index + 1) * 10
                ]
            ]);
            
            if (isset($itemResult['error'])) {
                echo "      ✗ Ошибка создания пункта: " . 
                     $itemResult['error_description'] . "\n";
            } else {
                $status = $item['is_completed'] ? '✓' : '☐';
                echo "      {$status} Пункт: {$item['title']}\n";
            }
            
            usleep(50000); // 50ms пауза
        }
    }
    
    return $checklistId;
}

/**
 * Импортирует все чек-листы из задачи Planfix в задачу Битрикс
 */
function importChecklistsFromPlanfixToBitrix($bitrixTaskId, $planfixTaskId)
{
    global $baseUrl, $apiToken;
    
    echo "\n" . str_repeat("-", 60) . "\n";
    echo "ИМПОРТ ЧЕК-ЛИСТОВ:\n";
    echo "Bitrix Task ID: {$bitrixTaskId}\n";
    echo "Planfix Task ID: {$planfixTaskId}\n";
    echo str_repeat("-", 60) . "\n";
    
    // 1. Получаем чек-листы из Planfix
    $checklists = getPlanfixTaskChecklists($planfixTaskId);

    if (empty($checklists)) {
        return [
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
    }
    
    // 2. Проверяем, есть ли уже чек-листы в задаче Битрикс
    echo "  Проверка существующих чек-листов в Битрикс...\n";
    $existingResult = CRest::call('task.checklist.getlist', [
        'taskId' => $bitrixTaskId
    ]);
    
    $existingChecklists = [];
    if (!isset($existingResult['error']) && !empty($existingResult['result'])) {
        $existingChecklists = $existingResult['result'];
        echo "  В задаче уже есть " . count($existingChecklists) . " чек-листов\n";
    }
    
    $importedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    
    // 3. Импортируем каждый чек-лист
    foreach ($checklists as $checklistIndex => $checklist) {
        $checklistNumber = $checklistIndex + 1;
        $checklistTitle = $checklist['name'] ?? "Чек-лист {$checklistNumber}";
        $items = $checklist['items'] ?? [];
        
        echo "\n  Чек-лист {$checklistNumber}: {$checklistTitle}\n";
        echo "    Пунктов: " . count($items) . "\n";
        
        // Проверяем, нет ли уже такого чек-листа
        $alreadyExists = false;
        foreach ($existingChecklists as $existing) {
            if (stripos($existing['TITLE'], $checklistTitle) !== false || 
                stripos($checklistTitle, $existing['TITLE']) !== false) {
                $alreadyExists = true;
                echo "    ⚠ Чек-лист уже существует в Битрикс, пропускаем\n";
                $skippedCount++;
                break;
            }
        }
        
        if ($alreadyExists) {
            continue;
        }
        
        // Подготавливаем пункты
        $preparedItems = [];
        foreach ($items as $item) {
            $preparedItems[] = [
                'title' => $item['name'] ?? 'Пункт',
                'is_completed' => !empty($item['isDone'])
            ];
        }
        
        // Создаем чек-лист в Битрикс
        $checklistId = createBitrixChecklist($bitrixTaskId, $checklistTitle, $preparedItems, $isDone['isDone'] === false ?? 'N', 'Y');
        
        if ($checklistId) {
            $importedCount++;
            echo "    ✓ Импортирован\n";
        } else {
            $errorCount++;
            echo "    ✗ Ошибка импорта\n";
        }
        
        // Пауза между чек-листами
        usleep(200000); // 200ms
    }
    
    echo "\n" . str_repeat("-", 60) . "\n";
    echo "ИТОГИ ИМПОРТА ДЛЯ ЗАДАЧИ {$bitrixTaskId}:\n";
    echo str_repeat("-", 60) . "\n";
    echo "Всего чек-листов в Planfix: " . count($checklists) . "\n";
    echo "Импортировано: {$importedCount}\n";
    echo "Пропущено (уже есть): {$skippedCount}\n";
    echo "Ошибок: {$errorCount}\n";
    
    return [
        'imported' => $importedCount,
        'skipped' => $skippedCount,
        'errors' => $errorCount
    ];
}

/**
 * Основная функция импорта чек-листов для всех активных задач
 */
function importChecklistsForAllActiveTasks($limit = 50)
{
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ИМПОРТ ЧЕК-ЛИСТОВ ДЛЯ ВСЕХ АКТИВНЫХ ЗАДАЧ\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // 1. Получаем активные задачи из Битрикс
    $bitrixTasks = getActiveBitrixTasksWithPlanfixId($limit);
    
    if (empty($bitrixTasks)) {
        echo "Нет активных задач для обработки\n";
        return [
            'total_tasks' => 0,
            'total_imported' => 0,
            'total_errors' => 0,
            'details' => []
        ];
    }
    
    echo "\nВсего активных задач для обработки: " . count($bitrixTasks) . "\n\n";
    
    $totalImported = 0;
    $totalErrors = 0;
    $processedTasks = 0;
    $details = [];
    
    // 2. Обрабатываем каждую задачу
    foreach ($bitrixTasks as $taskIndex => $task) {
        $processedTasks++;
        $taskNumber = $taskIndex + 1;
        $bitrixTaskId = $task['id'];
        $bitrixTaskTitle = $task['title'];
        $planfixTaskId = $task['ufAuto127801401239'] ?? null;
        
        echo "\n" . str_repeat("-", 70) . "\n";
        echo "ЗАДАЧА {$taskNumber}/" . count($bitrixTasks) . ":\n";
        echo "Битрикс: {$bitrixTaskTitle} (ID: {$bitrixTaskId})\n";
        echo "Planfix ID: {$planfixTaskId}\n";
        echo str_repeat("-", 70) . "\n";
        
        if (!$planfixTaskId) {
            echo "✗ Нет Planfix ID, пропускаем\n";
            $details[] = [
                'bitrix_task_id' => $bitrixTaskId,
                'bitrix_task_title' => $bitrixTaskTitle,
                'planfix_task_id' => null,
                'status' => 'skipped',
                'reason' => 'No Planfix ID'
            ];
            continue;
        }
        
        // Импортируем чек-листы
        $result = importChecklistsFromPlanfixToBitrix($bitrixTaskId, $planfixTaskId);
        
        $details[] = [
            'bitrix_task_id' => $bitrixTaskId,
            'bitrix_task_title' => $bitrixTaskTitle,
            'planfix_task_id' => $planfixTaskId,
            'status' => $result['errors'] > 0 ? 'partial' : 'success',
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors']
        ];
        
        $totalImported += $result['imported'];
        $totalErrors += $result['errors'];
        
        // Пауза между задачами
        if ($processedTasks < count($bitrixTasks)) {
            sleep(1); // 1 секунда паузы
        }
    }
    
    // 3. Выводим итоги
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ИТОГИ ИМПОРТА ЧЕК-ЛИСТОВ\n";
    echo str_repeat("=", 80) . "\n";
    echo "Обработано задач: {$processedTasks}\n";
    echo "Всего импортировано чек-листов: {$totalImported}\n";
    echo "Всего ошибок: {$totalErrors}\n\n";
    
    // Подробный отчет
    if ($totalImported > 0) {
        echo "ОБРАБОТАННЫЕ ЗАДАЧИ:\n";
        echo str_repeat("-", 80) . "\n";
        
        foreach ($details as $detail) {
            if ($detail['status'] !== 'skipped') {
                echo "• {$detail['bitrix_task_title']}\n";
                echo "  Битрикс ID: {$detail['bitrix_task_id']}\n";
                echo "  Planfix ID: {$detail['planfix_task_id']}\n";
                echo "  Статус: {$detail['status']}\n";
                echo "  Импортировано чек-листов: {$detail['imported']}\n";
                
                if ($detail['skipped'] > 0) {
                    echo "  Пропущено (дубли): {$detail['skipped']}\n";
                }
                
                if ($detail['errors'] > 0) {
                    echo "  Ошибок: {$detail['errors']}\n";
                }
                
                echo "\n";
            }
        }
    }
    
    return [
        'total_tasks' => $processedTasks,
        'total_imported' => $totalImported,
        'total_errors' => $totalErrors,
        'details' => $details
    ];
}

/**
 * Функция для импорта чек-листов для конкретной задачи
 */
function importChecklistsForSingleTask($bitrixTaskId, $planfixTaskId)
{
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ИМПОРТ ЧЕК-ЛИСТОВ ДЛЯ ОДНОЙ ЗАДАЧИ\n";
    echo str_repeat("=", 80) . "\n\n";
    
    // Получаем информацию о задаче
    $taskResult = CRest::call('tasks.task.get', [
        'taskId' => $bitrixTaskId,
        'select' => ['ID', 'TITLE', 'UF_AUTO_127801401239']
    ]);
    
    if (isset($taskResult['error'])) {
        echo "Ошибка получения задачи: " . $taskResult['error_description'] . "\n";
        return false;
    }
    
    $task = $taskResult['result']['task'];
    $taskTitle = $task['title'];
    
    echo "Задача: {$taskTitle}\n";
    echo "Bitrix ID: {$bitrixTaskId}\n";
    echo "Planfix ID: {$planfixTaskId}\n\n";
    
    // Выполняем импорт
    $result = importChecklistsFromPlanfixToBitrix($bitrixTaskId, $planfixTaskId);
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ИМПОРТ ЗАВЕРШЕН\n";
    echo str_repeat("=", 80) . "\n";
    
    return $result;
}

// =================== ВЫБОР РЕЖИМА РАБОТЫ ===================
echo "СКРИПТ ИМПОРТА ЧЕК-ЛИСТОВ ИЗ PLANFIX В BITRIX\n";
echo "==================================================\n\n";

// Выбор режима работы
echo "Выберите режим работы:\n";
echo "1. Импорт для всех активных задач\n";
echo "2. Импорт для конкретной задачи\n";
echo "3. Тестовый режим (первые 5 задач)\n";
echo "\nВведите номер (1-3): ";

// Для автоматического запуска можно закомментировать выбор и указать режим явно
$mode = 1; // По умолчанию - импорт для всех активных задач

switch ($mode) {
    case '1':
        // Импорт для всех активных задач
        $result = importChecklistsForAllActiveTasks(12000);
        
        // Сохраняем результаты в файл
        $logFile = __DIR__ . '/checklist_import_log_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($logFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "\nРезультаты сохранены в: {$logFile}\n";
        break;
        
    case '2':
        // Импорт для конкретной задачи
        echo "\nВведите Bitrix Task ID: ";
        $bitrixTaskId = trim(fgets(STDIN));
        
        echo "Введите Planfix Task ID: ";
        $planfixTaskId = trim(fgets(STDIN));
        
        if (empty($bitrixTaskId) || empty($planfixTaskId)) {
            echo "Ошибка: необходимо указать оба ID\n";
            exit;
        }
        
        $result = importChecklistsForSingleTask($bitrixTaskId, $planfixTaskId);
        break;
        
    case '3':
        // Тестовый режим - первые 5 задач
        echo "\nТЕСТОВЫЙ РЕЖИМ (первые 5 задач)\n";
        $result = importChecklistsForAllActiveTasks(5);
        break;
        
    default:
        echo "Неверный выбор режима\n";
        exit;
}

echo "\n" . str_repeat("*", 80) . "\n";
echo "СКРИПТ ВЫПОЛНЕН УСПЕШНО\n";
echo str_repeat("*", 80) . "\n";
?>