<?php
require_once dirname(__DIR__) . '/crest.php';

// =================== КОНФИГУРАЦИЯ ===================
$baseUrl = rtrim($_ENV['PLANFIX_BASE_URL'] ?? getenv('PLANFIX_BASE_URL') ?: '', '/') . '/';
$apiToken = $_ENV['PLANFIX_API_TOKEN'] ?? getenv('PLANFIX_API_TOKEN') ?: '';
if ($baseUrl === '/' || $apiToken === '') {
    fwrite(STDERR, "Set PLANFIX_BASE_URL and PLANFIX_API_TOKEN in .env\n");
    exit(1);
}

// =================== КОНФИГУРАЦИЯ ПЕРЕНОСА ===================
$iterations = 10; // Количество итераций для переноса
$projectsPerIteration = 50; // Количество проектов за одну итерацию

// =================== ФУНКЦИИ ДЛЯ РАБОТЫ С СОПОСТАВЛЕНИЯМИ ===================

/**
 * Загружает сопоставление пользователей из файла
 */
function loadUserMapping()
{
    $mappingFile = __DIR__ . '/user_mapping.json';
    
    if (!file_exists($mappingFile)) {
        file_put_contents($mappingFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Создан пустой файл сопоставления пользователей\n";
        return [];
    }
    
    $content = file_get_contents($mappingFile);
    $mapping = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Ошибка загрузки сопоставления пользователей: " . json_last_error_msg() . "\n";
        return [];
    }
    
    return $mapping ?: [];
}

/**
 * Загружает сопоставление проектов-сделок из файла
 */
function loadProjectMapping()
{
    $mappingFile = __DIR__ . '/project_mapping.json';
    
    if (!file_exists($mappingFile)) {
        file_put_contents($mappingFile, json_encode([], JSON_PRETTY_PRINT));
        echo "Создан пустой файл сопоставления проектов\n";
        return [];
    }
    
    $content = file_get_contents($mappingFile);
    $mapping = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Ошибка загрузки сопоставления проектов: " . json_last_error_msg() . "\n";
        return [];
    }
    
    return $mapping ?: [];
}

/**
 * Сохраняет сопоставление проектов в файл
 */
function saveProjectMapping($mapping)
{
    $mappingFile = __DIR__ . '/project_mapping.json';
    $result = file_put_contents($mappingFile, json_encode($mapping, JSON_PRETTY_PRINT));
    
    if ($result === false) {
        echo "Ошибка сохранения сопоставления проектов\n";
        return false;
    }
    
    return true;
}

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

// =================== ФУНКЦИЯ ДЛЯ ПОИСКА ПОЛЬЗОВАТЕЛЯ В БИТРИКС ===================
function findBitrixUser($userName, $userMapping)
{
    if (empty($userName)) {
        return null;
    }

    $userName = trim($userName);
    
    // 1. Прямое совпадение полного имени
    if (isset($userMapping[$userName])) {
        return $userMapping[$userName];
    }
    
    // 2. Разбиваем введенное имя на части
    $inputParts = array_filter(array_map('trim', explode(' ', $userName)));
    
    // Проходим по всем пользователям в маппинге
    foreach ($userMapping as $mappedName => $bitrixId) {
        $mappedParts = array_filter(array_map('trim', explode(' ', $mappedName)));
        
        // Проверяем полное совпадение всех частей (без учета порядка)
        $allPartsMatch = true;
        foreach ($inputParts as $inputPart) {
            $partFound = false;
            foreach ($mappedParts as $mappedPart) {
                // Сравниваем без учета регистра
                if (strcasecmp($inputPart, $mappedPart) === 0) {
                    $partFound = true;
                    break;
                }
            }
            if (!$partFound) {
                $allPartsMatch = false;
                break;
            }
        }
        
        if ($allPartsMatch) {
            return $bitrixId;
        }
    }
    
    // 3. Попытка найти по фамилии и имени (если ввод был в другом порядке)
    if (count($inputParts) >= 2) {
        foreach ($userMapping as $mappedName => $bitrixId) {
            $mappedParts = array_filter(array_map('trim', explode(' ', $mappedName)));
            
            // Проверяем, что в маппинге есть хотя бы фамилия и имя
            if (count($mappedParts) >= 2) {
                $foundFirstName = false;
                $foundLastName = false;
                
                // Проверяем совпадение имени
                foreach ($inputParts as $inputPart) {
                    if (strcasecmp($inputPart, $mappedParts[0]) === 0) {
                        $foundFirstName = true;
                    }
                    if (count($mappedParts) > 1 && strcasecmp($inputPart, $mappedParts[1]) === 0) {
                        $foundLastName = true;
                    }
                }
                
                if ($foundFirstName && $foundLastName) {
                    return $bitrixId;
                }
            }
        }
    }
    
    return null;
}

// =================== ПОЛУЧЕНИЕ И ОБРАБОТКА ПРОЕКТОВ ===================

/**
 * Получает список проектов из Planfix
 */
function getPlanfixProjects($offset, $limit)
{
    global $baseUrl, $apiToken;
    
    $projectsEndpoint = $baseUrl . 'project/list';
    
    $requestData = [
        "pageSize" => $limit,
        "offset" => $offset,
        "fields" => "id,name,description,status,parent,startDate,endDate,assignees,participants"
    ];
    
    echo "Получаем проекты из Planfix (offset: {$offset}, limit: {$limit})...\n";
    $result = makePlanfixRequest($projectsEndpoint, $apiToken, $requestData);
    
    if (!$result['success']) {
        echo "Ошибка при получении проектов: HTTP код " . 
             ($result['http_code'] ?? 'N/A') . "\n";
        return [];
    }
    
    $projects = $result['data']['projects'] ?? [];
    echo "Успешно получено проектов: " . count($projects) . "\n";
    
    return $projects;
}

/**
 * Преобразует пользователей проекта в ID пользователей Битрикс
 */
function processProjectUsers($projectData, $userMapping)
{
    $assigneesIds = [];
    $participantsIds = [];
    
    // Обработка ответственных (assignees)
    if (!empty($projectData['assignees']) && is_array($projectData['assignees'])) {
        // Если assignees - пользователи
        if (isset($projectData['assignees']['users']) && is_array($projectData['assignees']['users'])) {
            foreach ($projectData['assignees']['users'] as $user) {
                if (!empty($user['name'])) {
                    $bitrixId = findBitrixUser($user['name'], $userMapping);
                    if ($bitrixId) {
                        $assigneesIds[] = $bitrixId;
                    }
                }
            }
        }
        
        // Если assignees - группы
        if (isset($projectData['assignees']['groups']) && is_array($projectData['assignees']['groups'])) {
            foreach ($projectData['assignees']['groups'] as $group) {
                if (!empty($group['name'])) {
                    // Для групп можно использовать специальную логику или пропускать
                    echo "  Группа-ответственный: {$group['name']} (пропускаем)\n";
                }
            }
        }
    }
    
    // Обработка участников (participants)
    if (!empty($projectData['participants']) && is_array($projectData['participants'])) {
        // Если participants - пользователи
        if (isset($projectData['participants']['users']) && is_array($projectData['participants']['users'])) {
            foreach ($projectData['participants']['users'] as $user) {
                if (!empty($user['name'])) {
                    $bitrixId = findBitrixUser($user['name'], $userMapping);
                    if ($bitrixId) {
                        $participantsIds[] = $bitrixId;
                    }
                }
            }
        }
        
        // Если participants - группы
        if (isset($projectData['participants']['groups']) && is_array($projectData['participants']['groups'])) {
            foreach ($projectData['participants']['groups'] as $group) {
                if (!empty($group['name'])) {
                    echo "  Группа-участник: {$group['name']} (пропускаем)\n";
                }
            }
        }
    }
    
    // Убираем дубликаты
    $assigneesIds = array_unique($assigneesIds);
    $participantsIds = array_unique($participantsIds);
    
    // Убираем ответственных из участников
    $participantsIds = array_diff($participantsIds, $assigneesIds);
    
    return [
        'assignees' => $assigneesIds,
        'participants' => $participantsIds
    ];
}

/**
 * Преобразует HTML в простой текст
 */
function htmlToPlainText($html)
{
    if (empty($html)) {
        return '';
    }
    
    $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['</p>', '</div>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>'], "\n", $html);
    $html = str_replace('<li>', '• ', $html);
    
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text);
    
    return trim($text);
}

/**
 * Находит сделку по Planfix ID
 */
function findBitrixDealByPlanfixId($planfixId)
{
    $result = CRest::call('crm.deal.list', [
        'filter' => ['UF_CRM_1765266167572' => $planfixId],
        'select' => ['ID', 'TITLE']
    ]);
    
    if (isset($result['error'])) {
        echo "Ошибка поиска сделки: " . $result['error_description'] . "\n";
        return null;
    }
    
    if (!empty($result['result'])) {
        return $result['result'][0];
    }
    
    return null;
}

/**
 * Создает или обновляет сделку в Битрикс
 */
function createOrUpdateBitrixDeal($projectData, $usersData)
{
    $planfixId = $projectData['id'] ?? null;
    $projectName = $projectData['name'] ?? 'Без названия';
    
    if (!$planfixId) {
        echo "Ошибка: у проекта нет ID\n";
        return null;
    }
    
    // Ищем существующую сделку
    $existingDeal = findBitrixDealByPlanfixId($planfixId);

    // Подготавливаем поля для сделки
    $fields = [
        'TITLE' => $projectName,
        'UF_CRM_1763112549' => htmlToPlainText($projectData['description'] ?? ''),
        'UF_CRM_1763474364483' => $projectData['status']['id'] == 1 ? 'Завершен' : 'Активен',
        'UF_CRM_1768398228' => $projectData['parent'] ?? '',
        'UF_CRM_1763446474906' => $projectData['startDate']['dateTimeUtcSeconds'] ?? '',
        'UF_CRM_1763446483351' => $projectData['endDate']['dateTimeUtcSeconds'] ?? '',
        'UF_CRM_1765266167572' => $planfixId, // Planfix ID
        'CATEGORY_ID' => 43, // ID категории сделок
        'STAGE_ID' => 'NEW',
    ];
    
    // Добавляем привязки к пользователям
    if (!empty($usersData['assignees'])) {
        $fields['UF_CRM_1765266032'] = $usersData['assignees'];
    }
    
    if (!empty($usersData['participants'])) {
        $fields['UF_CRM_1765265981'] = $usersData['participants'];
    }
    
    if ($existingDeal) {
        // Обновляем существующую сделку
        echo "Обновляем существующую сделку: {$existingDeal['ID']} - {$existingDeal['TITLE']}\n";
        
        $result = CRest::call('crm.deal.update', [
            'id' => $existingDeal['ID'],
            'fields' => $fields
        ]);
        
        if (isset($result['error'])) {
            echo "Ошибка обновления сделки: " . $result['error_description'] . "\n";
            return null;
        }
        
        echo "✓ Сделка обновлена (ID: {$existingDeal['ID']})\n";
        return $existingDeal['ID'];
    } else {
        // Создаем новую сделку
        echo "Создаем новую сделку для проекта: {$projectName}\n";
        
        $result = CRest::call('crm.deal.add', [
            'fields' => $fields
        ]);
        
        if (isset($result['error'])) {
            echo "Ошибка создания сделки: " . $result['error_description'] . "\n";
            return null;
        }
        
        $dealId = $result['result'];
        echo "✓ Сделка создана (ID: {$dealId})\n";
        
        // Добавляем в маппинг
        addProjectToMapping($planfixId, $dealId);
        
        return $dealId;
    }
}

/**
 * Добавляет проект в маппинг
 */
function addProjectToMapping($planfixId, $bitrixDealId)
{
    global $projectMapping;
    
    if (empty($planfixId) || empty($bitrixDealId)) {
        return false;
    }
    
    $projectMapping[$planfixId] = $bitrixDealId;
    
    return saveProjectMapping($projectMapping);
}

// =================== ОСНОВНАЯ ФУНКЦИЯ ПЕРЕНОСА ПРОЕКТОВ ===================
function transferProjects($currentOffset = 0)
{
    global $projectsPerIteration, $userMapping, $projectMapping;
    
    $projects = getPlanfixProjects($currentOffset, 100);
    
    if (empty($projects)) {
        echo "Нет проектов для обработки\n";
        return [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'offset' => $currentOffset
        ];
    }
    
    $processed = 0;
    $created = 0;
    $updated = 0;
    
    foreach ($projects as $index => $project) {
        echo "\n" . str_repeat("-", 60) . "\n";
        echo "Обрабатываем проект #" . ($index + 1) . "\n";
        echo "Planfix ID: " . ($project['id'] ?? 'N/A') . "\n";
        echo "Название: " . ($project['name'] ?? 'Без названия') . "\n";
        
        // Обрабатываем пользователей проекта
        echo "Обрабатываем пользователей проекта...\n";
        $usersData = processProjectUsers($project, $userMapping);
        
        echo "  Ответственные (ID Битрикс): " . implode(', ', $usersData['assignees']) . "\n";
        echo "  Участники (ID Битрикс): " . implode(', ', $usersData['participants']) . "\n";
        
        // Создаем или обновляем сделку
        $dealId = createOrUpdateBitrixDeal($project, $usersData);
        
        if ($dealId) {
            // Проверяем, была ли это новая сделка или обновление
            $existingDeal = findBitrixDealByPlanfixId($project['id']);
            if ($existingDeal && $existingDeal['ID'] == $dealId) {
                $updated++;
            } else {
                $created++;
            }
        }
        
        $processed++;
        
        // Небольшая пауза между запросами
        usleep(100000); // 0.1 секунда
    }
    
    return [
        'processed' => $processed,
        'created' => $created,
        'updated' => $updated,
        'offset' => $currentOffset + $processed
    ];
}

// =================== ОСНОВНОЙ СКРИПТ ===================

// Загружаем сопоставления
$userMapping = loadUserMapping();
$projectMapping = loadProjectMapping();

echo "Текущее сопоставление пользователей: " . count($userMapping) . " записей\n";
echo "Текущее сопоставление проектов: " . count($projectMapping) . " записей\n\n";

echo "НАСТРОЙКИ ПЕРЕНОСА ПРОЕКТОВ:\n";
echo "• Количество итераций: {$iterations}\n";
echo "• Проектов за итерацию: {$projectsPerIteration}\n\n";

// Основной цикл переноса
$allResults = [];
$currentOffset = 0;
$iteration = 1;
$totalCreated = 0;
$totalUpdated = 0;

while ($iteration <= $iterations) {
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "ИТЕРАЦИЯ {$iteration} из {$iterations}\n";
    echo str_repeat("=", 80) . "\n";
    
    $result = transferProjects($currentOffset);
    
    $allResults[] = $result;
    $totalCreated += $result['created'];
    $totalUpdated += $result['updated'];
    
    $currentOffset = $result['offset'];
    $iteration++;
    
    // Если обработано меньше проектов, чем лимит, значит достигнут конец
    if ($result['processed'] < $projectsPerIteration) {
        echo "Достигнут конец списка проектов. Завершение.\n";
        break;
    }
    
    if ($iteration <= $iterations) {
        echo "\nПауза перед следующей итерацией...\n";
        sleep(1);
    }
}

// =================== ВЫВОД ИТОГОВ ===================
echo "\n" . str_repeat("=", 80) . "\n";
echo "ИТОГИ ПЕРЕНОСА ПРОЕКТОВ\n";
echo str_repeat("=", 80) . "\n";

$totalProcessed = 0;
foreach ($allResults as $result) {
    $totalProcessed += $result['processed'];
}

echo "Всего обработано проектов: {$totalProcessed}\n";
echo "Создано новых сделок: {$totalCreated}\n";
echo "Обновлено существующих сделок: {$totalUpdated}\n";
echo "Выполнено итераций: " . ($iteration - 1) . "\n";

echo "\n" . str_repeat("=", 80) . "\n";
echo "ПЕРЕНОС ПРОЕКТОВ ЗАВЕРШЕН\n";
echo str_repeat("=", 80) . "\n";