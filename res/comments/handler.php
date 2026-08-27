<?php
// Настройки
require_once dirname(__DIR__, 2) . '/crest.php';

// =================== ФУНКЦИЯ ДЛЯ ВЫПОЛНЕНИЯ API-ЗАПРОСОВ ===================
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
        return ['error' => 'Ошибка cURL: ' . $curlError];
    }

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => $result];
    } else {
        return ['success' => false, 'http_code' => $httpCode, 'data' => $result];
    }
}

/**
 * Получает чек-листы задачи из Planfix
 */
function getPlanfixTaskChecklists($taskId, $apiToken, $baseUrl)
{
    $checklistsEndpoint = $baseUrl . 'task/' . $taskId . '/checklist/list';
    
    echo "  Получение чек-листов задачи из Planfix...\n";
    $checklistsResult = makePlanfixRequest($checklistsEndpoint, $apiToken);

    if (!$checklistsResult['success']) {
        echo "  Ошибка получения чек-листов: HTTP код " . 
             ($checklistsResult['http_code'] ?? 'N/A') . "\n";
        return [];
    }
    
    $checklists = $checklistsResult['data']['checklists'] ?? [];
    echo "  Найдено чек-листов в Planfix: " . count($checklists) . "\n";
    
    return $checklists;
}

/**
 * Получает подробную информацию о чек-листе из Planfix
 */
function getPlanfixChecklistDetails($checklistId, $apiToken, $baseUrl)
{
    $checklistEndpoint = $baseUrl . 'task/checklist/' . $checklistId;
    
    $data = [
        "fields" => "name,items"
    ];
    
    echo "  Получение деталей чек-листа {$checklistId}...\n";
    $checklistResult = makePlanfixRequest($checklistEndpoint, $apiToken, $data);

    if (!$checklistResult['success']) {
        echo "  Ошибка получения деталей чек-листа: HTTP код " . 
             ($checklistResult['http_code'] ?? 'N/A') . "\n";
        return null;
    }
    
    return $checklistResult['data']['checklist'] ?? null;
}

/**
 * Batch поиск задач в Битрикс24 по Planfix IDs (номерам)
 * @param array $planfixIds - до 50 ID
 * @return array planfixId => bitrixTaskId
 */
function findTasksByPlanfixIds(array $planfixIds) {
    if (empty($planfixIds)) {
        return [];
    }
    $map = [];
    $chunks = array_chunk(array_unique($planfixIds), 50);
    foreach ($chunks as $chunk) {
        $result = CRest::call('tasks.task.list', [
            'filter' => [
                'UF_AUTO_127801401239' => $chunk
            ],
            'select' => ['ID', 'TITLE', 'UF_AUTO_127801401239']
        ]);
        foreach ($result['result']['tasks'] ?? [] as $task) {
            $pfId = $task['ufAuto127801401239'];
            $map[$pfId] = [
                'id' => $task['id'],
                'title' => $task['title'] ?? ''
            ];
        }
    }
    return $map;
}

// Backward compatibility
function findTaskByPlanfixId($planfixId) {
    if (empty($planfixId)) return null;
    $map = findTasksByPlanfixIds([$planfixId]);
    return $map[$planfixId] ?? null;
}

/**
 * Проверяет, есть ли уже чек-лист в задаче Битрикс24
 */
function hasExistingChecklist($taskId) {
    // Получаем все чек-листы задачи
    $result = CRest::call('task.checklist.getlist', [
        'taskId' => $taskId
    ]);
    
    if ($result && !empty($result['result'])) {
        return count($result['result']) > 0;
    }
    
    return false;
}

/**
 * Создает чек-лист в задаче Битрикс24
 */
function createBitrixChecklist($taskId, $title, $items = []) {
    // Создаем основной чек-лист
    $checklistResult = CRest::call('task.checklist.add', [
        'taskId' => $taskId,
        'fields' => [
            'TITLE' => $title,
            'IS_COMPLETE' => 'N'
        ]
    ]);
    
    if (!$checklistResult || isset($checklistResult['error'])) {
        echo "    ✗ Ошибка создания чек-листа: " . 
             ($checklistResult['error_description'] ?? 'Неизвестная ошибка') . "\n";
        return false;
    }
    
    $checklistId = $checklistResult['result']['checklist']['id'] ?? null;
    if (!$checklistId) {
        echo "    ✗ Не удалось получить ID созданного чек-листа\n";
        return false;
    }
    
    echo "    ✓ Создан чек-лист: {$title} (ID: {$checklistId})\n";
    
    // Добавляем элементы чек-листа
    $itemsAdded = 0;
    foreach ($items as $item) {
        if (!empty($item['title'])) {
            $itemResult = CRest::call('task.checklist.additem', [
                'taskId' => $taskId,
                'checklistId' => $checklistId,
                'fields' => [
                    'TITLE' => $item['title'],
                    'IS_COMPLETE' => isset($item['isChecked']) && $item['isChecked'] ? 'Y' : 'N'
                ]
            ]);
            
            if ($itemResult && !isset($itemResult['error'])) {
                $itemsAdded++;
            }
            
            // Небольшая пауза между запросами
            usleep(100000); // 100ms
        }
    }
    
    if ($itemsAdded > 0) {
        echo "    ✓ Добавлено элементов: {$itemsAdded}\n";
    }
    
    return true;
}

/**
 * Обрабатывает чек-листы из Planfix
 */
function processPlanfixChecklists($planfixId, $planfixChecklists, $bitrixTaskId, $apiToken, $baseUrl) {
    if (empty($planfixChecklists)) {
        echo "  Нет чек-листов в Planfix для переноса\n";
        return 0;
    }
    
    $processed = 0;
    
    foreach ($planfixChecklists as $checklist) {
        $checklistId = $checklist['id'] ?? null;
        if (!$checklistId) {
            continue;
        }
        
        echo "  Обработка чек-листа из Planfix (ID: {$checklistId})\n";
        
        // Получаем детальную информацию о чек-листе
        $checklistDetails = getPlanfixChecklistDetails($checklistId, $apiToken, $baseUrl);
        if (!$checklistDetails) {
            echo "    ✗ Не удалось получить детали чек-листа\n";
            continue;
        }
        
        $checklistName = $checklistDetails['name'] ?? 'Чек-лист из Planfix';
        $checklistItems = $checklistDetails['items'] ?? [];
        
        // Формируем элементы для Битрикс24
        $bitrixItems = [];
        foreach ($checklistItems as $item) {
            if (!empty($item['text'])) {
                $bitrixItems[] = [
                    'title' => strip_tags($item['text']),
                    'isChecked' => isset($item['isChecked']) ? (bool)$item['isChecked'] : false
                ];
            }
        }
        
        // Создаем чек-лист в Битрикс24
        if (createBitrixChecklist($bitrixTaskId, $checklistName, $bitrixItems)) {
            echo "    ✓ Чек-лист успешно добавлен\n";
            $processed++;
        }
        
        // Пауза между запросами
        sleep(0.2);
    }
    
    return $processed;
}

/**
 * Получает задачи из Битрикс24 с фильтрацией по дате создания
 */
function getBitrixTasksWithPlanfixId($startDate = '2025-11-01', $page = 1, $pageSize = 50) {
    // Конвертируем дату в формат Битрикс
    $dateFormatted = $startDate . 'T00:00:00';
    
    $result = CRest::call('tasks.task.list', [
        'filter' => [
            '>=CREATED_DATE' => $dateFormatted,
            '!UF_AUTO_127801401239' => false, // Только задачи с Planfix ID
        ],
        'select' => ['ID', 'TITLE', 'UF_AUTO_127801401239', 'CREATED_DATE'],
        'order' => ['ID' => 'ASC'],
        'start' => ($page - 1) * $pageSize,
    ]);
    
    if (!$result || isset($result['error'])) {
        echo "Ошибка получения задач из Битрикс24: " . 
             ($result['error_description'] ?? 'Неизвестная ошибка') . "\n";
        return [];
    }
    
    $tasks = [];
    if (!empty($result['result']['tasks'])) {
        foreach ($result['result']['tasks'] as $task) {
            $planfixId = $task['ufAuto127801401239'] ?? null;
            if ($planfixId) {
                $tasks[] = [
                    'bitrix_id' => $task['id'],
                    'bitrix_title' => $task['title'] ?? '',
                    'planfix_id' => $planfixId,
                    'created_date' => $task['createdDate'] ?? '',
                ];
            }
        }
    }
    
    return $tasks;
}

/**
 * Получает общее количество задач для пагинации
 */
function getBitrixTasksCount($startDate = '2025-11-01') {
    $dateFormatted = $startDate . 'T00:00:00';
    
    $result = CRest::call('tasks.task.list', [
        'filter' => [
            '>=CREATED_DATE' => $dateFormatted,
            '!UF_AUTO_127801401239' => false,
        ],
        'select' => ['ID'],
    ]);
    
    if (!$result || isset($result['error'])) {
        return 0;
    }
    
    return $result['result']['total'];
}

/**
 * Основная функция обработки задач из Битрикс24 для чек-листов
 */
function processChecklistsFromBitrix($startDate = '2025-11-01', $planfixApiToken = '', $planfixBaseUrl = '', $pageSize = 50) {
    // Проверяем наличие данных для подключения к Planfix API
    $usePlanfixApi = !empty($planfixApiToken) && !empty($planfixBaseUrl);
    
    if ($usePlanfixApi) {
        echo "Режим работы: задачи из Битрикс24 + API Planfix для чек-листов\n";
    } else {
        echo "Режим работы: только задачи из Битрикс24 (API Planfix недоступно)\n";
    }
    
    echo "Фильтр: задачи созданные с {$startDate}\n";
    echo "Размер страницы: {$pageSize} задач\n\n";
    
    // Получаем общее количество задач для пагинации
    $totalTasks = getBitrixTasksCount($startDate);
    echo "Всего задач в Битрикс24 с Planfix ID: {$totalTasks}\n";
    
    if ($totalTasks == 0) {
        echo "Нет задач для обработки\n";
        return;
    }
    
    $totalPages = ceil($totalTasks / $pageSize);
    echo "Всего страниц для обработки: {$totalPages}\n\n";
    
    $processedChecklists = 0;
    $errors = 0;
    $skipped = 0;
    $processedTasks = 0;
    
    // Обрабатываем задачи постранично
    for ($page = 1; $page <= $totalPages; $page++) {
        echo "================ Страница {$page}/{$totalPages} ================\n";
        
        // Получаем задачи для текущей страницы
        $tasks = getBitrixTasksWithPlanfixId($startDate, $page, $pageSize);
        
        if (empty($tasks)) {
            echo "Нет задач на странице {$page}\n";
            continue;
        }
        
        echo "Найдено задач на странице: " . count($tasks) . "\n\n";
        
        foreach ($tasks as $index => $task) {
            $taskNumber = $processedTasks + $index + 1;
            echo "Задача {$taskNumber}/{$totalTasks}: \n";
            echo "  Битрикс24 ID: {$task['bitrix_id']}\n";
            echo "  Planfix ID: {$task['planfix_id']}\n";
            echo "  Название: {$task['bitrix_title']}\n";
            echo "  Дата создания: {$task['created_date']}\n";
            
            // Проверяем, есть ли уже чек-листы в задаче
            if (hasExistingChecklist($task['bitrix_id'])) {
                echo "  ⚠ Задача уже содержит чек-листы. Пропускаем...\n";
                $skipped++;
                sleep(0.2);
                continue;
            }
            
            // Если подключен API Planfix, получаем чек-листы из Planfix
            if ($usePlanfixApi) {
                echo "  Запрос чек-листов из Planfix API...\n";
                
                // Получаем чек-листы из Planfix
                $planfixChecklists = getPlanfixTaskChecklists($task['planfix_id'], $planfixApiToken, $planfixBaseUrl);
                
                if (!empty($planfixChecklists)) {
                    $addedChecklists = processPlanfixChecklists(
                        $task['planfix_id'], 
                        $planfixChecklists, 
                        $task['bitrix_id'], 
                        $planfixApiToken, 
                        $planfixBaseUrl
                    );
                    $processedChecklists += $addedChecklists;
                    
                    if ($addedChecklists == 0) {
                        echo "  ✓ Чек-листы не найдены или не удалось обработать\n";
                    }
                } else {
                    echo "  ✓ Чек-листов в Planfix не найдено\n";
                }
            } else {
                echo "  ⚠ API Planfix не подключено, чек-листы не получены\n";
            }
            
            // Небольшая пауза между запросами
            sleep(0.3);
            
            echo "\n";
        }
        
        $processedTasks += count($tasks);
        
        // Пауза между страницами
        if ($page < $totalPages) {
            echo "Пауза перед следующей страницей...\n";
            sleep(1);
        }
    }
    
    echo "\n========================================\n";
    echo "Обработка чек-листов завершена:\n";
    echo "Всего найдено задач: {$totalTasks}\n";
    echo "Обработано задач: {$processedTasks}\n";
    echo "Успешно добавлено чек-листов: {$processedChecklists}\n";
    echo "Ошибок: {$errors}\n";
    echo "Пропущено (уже есть чек-листы): {$skipped}\n";
}

// Запуск обработки
try {
    // Настройки для подключения к Planfix API (заполните своими данными)
    $planfixApiToken = $_ENV['PLANFIX_API_TOKEN'] ?? getenv('PLANFIX_API_TOKEN') ?: '';
    $planfixBaseUrl = rtrim($_ENV['PLANFIX_BASE_URL'] ?? getenv('PLANFIX_BASE_URL') ?: '', '/') . '/';
    
    // Настройки фильтрации
    $startDate = '2025.11.01'; // Дата начала фильтрации
    $pageSize = 50; // Количество задач на странице
    
    processChecklistsFromBitrix($startDate, $planfixApiToken, $planfixBaseUrl, $pageSize);
    
} catch (Exception $e) {
    echo "Произошла ошибка: " . $e->getMessage() . "\n";
}
?>