<?php
require_once(__DIR__ . '/crest.php');

class BitrixTaskImporter {
    private $jsonData;
    private $userCache = []; // Кэш для пользователей
    private $batchSize = 50; // Размер батча для поиска пользователей
    
    public function __construct($jsonFilePath)
    {
        $jsonContent = file_get_contents($jsonFilePath);
        $this->jsonData = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
        }
    }
    
    /**
     * Поиск задачи в Битрикс24 по полю UF_AUTO_127801401239
     */
    private function findTaskByCustomId($taskId)
    {
        $result = CRest::call('tasks.task.list', [
            'filter' => [
                'UF_AUTO_127801401239' => $taskId
            ],
            'select' => ['ID', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'CREATED_BY']
        ]);
        
        if (isset($result['result']['tasks']) && !empty($result['result']['tasks'])) {
            return $result['result']['tasks'][0];
        }
        
        return null;
    }
    
    /**
     * Пакетный поиск пользователей по именам
     */
    private function findUsersBatch($names)
    {
        $results = [];
        $batches = [];
        
        // Группируем имена в батчи
        foreach (array_chunk(array_unique($names), $this->batchSize) as $batchIndex => $nameBatch) {
            $commands = [];
            
            foreach ($nameBatch as $name) {
                if (empty($name) || isset($this->userCache[$name])) {
                    continue;
                }
                
                $nameParts = explode(' ', trim($name), 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';
                
                $key = md5($name);
                $commands[$key] = [
                    'method' => 'user.search',
                    'params' => [
                        'FILTER' => [
                            'NAME' => $firstName,
                            'LAST_NAME' => $lastName
                        ]
                    ]
                ];
            }
            
            if (!empty($commands)) {
                $batchResult = CRest::callBatch($commands, false);
                $batches[] = $batchResult;
            }
        }
        
        // Обрабатываем результаты батчей
        foreach ($batches as $batchResult) {
            if (isset($batchResult['result']['result'])) {
                foreach ($batchResult['result']['result'] as $key => $userResult) {
                    if (isset($userResult[0]['ID'])) {
                        // Извлекаем оригинальное имя из ключа
                        $originalName = '';
                        foreach ($names as $name) {
                            if (md5($name) === $key) {
                                $originalName = $name;
                                break;
                            }
                        }
                        
                        if ($originalName && !empty($userResult[0])) {
                            $this->userCache[$originalName] = $userResult[0]['ID'];
                            $results[$originalName] = $userResult[0]['ID'];
                        }
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Поиск пользователя в Битрикс24 по имени и фамилии
     */
    private function findUserIdByName($fullName)
    {
        if (empty($fullName)) {
            return null;
        }
        
        if ($fullName === "Отдел сервисного обслуживания (ОСО)" || 
            stripos($fullName, 'ОСО') !== false) {
            return 129;
        }
        
        // Проверяем кэш
        if (isset($this->userCache[$fullName])) {
            return $this->userCache[$fullName];
        }
        
        // Разделяем имя и фамилию
        $nameParts = explode(' ', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        
        $result = CRest::call('user.search', [
            'FILTER' => [
                'NAME' => $firstName,
                'LAST_NAME' => $lastName
            ]
        ]);
        
        if (isset($result['result']) && !empty($result['result']) && $result['result'][0]['NAME'] === $firstName) {
            $this->userCache[$fullName] = $result['result'][0]['ID'];
            return $result['result'][0]['ID'];
        }
        
        // Если не нашли, кэшируем как не найденного
        $this->userCache[$fullName] = null;
        return null;
    }
    
    /**
     * Обновление задачи в Битрикс24
     */
    private function updateTask($taskId, $fields)
    {
        $result = CRest::call('tasks.task.update', [
            'taskId' => $taskId,
            'fields' => $fields
        ]);
        
        return $result;
    }
    
    /**
     * Пакетное обновление задач
     */
    private function updateTasksBatch($updates)
    {
        $commands = [];
        $results = [];
        
        foreach ($updates as $index => $update) {
            $commands["task_update_{$index}"] = [
                'method' => 'tasks.task.update',
                'params' => [
                    'taskId' => $update['taskId'],
                    'fields' => $update['fields']
                ]
            ];
        }
        
        if (!empty($commands)) {
            $batchResult = CRest::callBatch($commands, false);

            if (isset($batchResult['result']['result'])) {
                foreach ($batchResult['result']['result'] as $key => $taskResult) {
                    // Извлекаем индекс из ключа
                    if (preg_match('/task_update_(\d+)/', $key, $matches)) {
                        $index = $matches[1];
                        $results[$index] = $taskResult;
                    }
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Определение исполнителя по приоритету
     */
    private function determineResponsible($taskData)
    {
        $responsibleId = null;
        $responsibleName = '';
        $foundBy = '';
        
        // Проверка на Отдел сервисного обслуживания (ОСО)
        if (!empty($taskData['assignees'])) {
            if (stripos($taskData['assignees'], 'ОСО') !== false || 
                stripos($taskData['assignees'], 'отдел сервисного обслуживания') !== false) {
                $responsibleId = 129;
                $responsibleName = 'Отдел сервисного обслуживания';
                $foundBy = 'OSO';
                return [$responsibleId, $responsibleName, $foundBy];
            }
        }
        
        // 1. Пробуем найти назначенного исполнителя из assignees
        if (!empty($taskData['assignees'])) {
            $responsibleId = $this->findUserIdByName($taskData['assignees']);
            if ($responsibleId) {
                $responsibleName = $taskData['assignees'];
                $foundBy = 'assignees';
                return [$responsibleId, $responsibleName, $foundBy];
            }
        }
        
        // 2. Если исполнитель не найден, ищем среди участников (participants)
        if (!empty($taskData['participants'])) {
            $participants = explode(',', $taskData['participants']);
            foreach ($participants as $participant) {
                $participant = trim($participant);
                if (!empty($participant)) {
                    // Проверка на ОСО среди участников
                    if (stripos($participant, 'ОСО') !== false || 
                        stripos($participant, 'отдел сервисного обслуживания') !== false) {
                        $responsibleId = 129;
                        $responsibleName = 'Отдел сервисного обслуживания';
                        $foundBy = 'OSO';
                        return [$responsibleId, $responsibleName, $foundBy];
                    }
                    
                    $userId = $this->findUserIdByName($participant);
                    if ($userId) {
                        $responsibleId = $userId;
                        $responsibleName = $participant;
                        $foundBy = 'participants';
                        return [$responsibleId, $responsibleName, $foundBy];
                    }
                }
            }
        }
        
        // 3. Если участники не найдены, пробуем постановщика
        if (!empty($taskData['creator'])) {
            // Проверка на ОСО в постановщике
            if (stripos($taskData['creator'], 'ОСО') !== false || 
                stripos($taskData['creator'], 'отдел сервисного обслуживания') !== false) {
                $responsibleId = 129;
                $responsibleName = 'Отдел сервисного обслуживания';
                $foundBy = 'OSO';
                return [$responsibleId, $responsibleName, $foundBy];
            }
            
            $responsibleId = $this->findUserIdByName($taskData['creator']);
            if ($responsibleId) {
                $responsibleName = $taskData['creator'];
                $foundBy = 'creator';
                return [$responsibleId, $responsibleName, $foundBy];
            }
        }
        
        // 4. Если никого не нашли, ставим пользователя с ID 627
        $responsibleId = 627;
        $responsibleName = 'Пользователь по умолчанию';
        $foundBy = 'default';
        
        return [$responsibleId, $responsibleName, $foundBy];
    }
    
    /**
     * Сбор всех имен пользователей для пакетного поиска
     */
    private function collectAllUserNames()
    {
        $allNames = [];
        
        foreach ($this->jsonData as $taskData) {
            // Собираем имена из assignees
            if (!empty($taskData['assignees']) && 
                stripos($taskData['assignees'], 'ОСО') === false) {
                $allNames[] = $taskData['assignees'];
            }
            
            // Собираем имена из participants
            if (!empty($taskData['participants'])) {
                $participants = explode(',', $taskData['participants']);
                foreach ($participants as $participant) {
                    $participant = trim($participant);
                    if (!empty($participant) && 
                        stripos($participant, 'ОСО') === false) {
                        $allNames[] = $participant;
                    }
                }
            }
            
            // Собираем имена из creator
            if (!empty($taskData['creator']) && 
                stripos($taskData['creator'], 'ОСО') === false) {
                $allNames[] = $taskData['creator'];
            }
        }
        
        return array_unique(array_filter($allNames));
    }
    
    public function processTasks()
    {
        $results = [];
        $counterFile = 'import_counter.txt';
        $startFrom = file_exists($counterFile) ? (int)file_get_contents($counterFile) : 0;
        
        echo "Сбор имен пользователей для пакетного поиска...\n";
        $allUserNames = $this->collectAllUserNames();
        echo "Найдено уникальных имен: " . count($allUserNames) . "\n";
        
        echo "Выполняем пакетный поиск пользователей...\n";
        $this->findUsersBatch($allUserNames);
        echo "Пакетный поиск завершен. Найдено пользователей: " . count($this->userCache) . "\n";
        
        $batchUpdates = [];
        $batchIndex = 0;
        
        foreach ($this->jsonData as $index => $taskData) {
            try {
                if ($index < $startFrom) {
                    continue;
                }
                
                echo "Обработка задачи #" . ($index + 1) . " (ID: {$taskData['id']})...\n";

                // 1. Ищем задачу в Битрикс по полю UF_AUTO_127801401239
                $bitrixTask = $this->findTaskByCustomId($taskData['id']);

                if (!$bitrixTask) {
                    $results[] = [
                        'task_id' => $taskData['id'],
                        'status' => 'error',
                        'message' => 'Задача не найдена в Битрикс'
                    ];
                    continue;
                }
                
                // 2. Определяем исполнителя по приоритету
                list($responsibleId, $responsibleName, $foundBy) = $this->determineResponsible($taskData);

                // 3. Получаем ID создателя
                $creatorId = null;
                if (!empty($taskData['creator'])) {
                    $creatorId = $this->findUserIdByName($taskData['creator']);
                }
                
                // 4. Получаем соисполнителей (кроме основного исполнителя)
                $accomplices = [];
                if (!empty($taskData['participants'])) {
                    $participants = explode(',', $taskData['participants']);
                    foreach ($participants as $participant) {
                        $participant = trim($participant);
                        if (!empty($participant)) {
                            $userId = $this->findUserIdByName($participant);
                            // Добавляем в соисполнители только если:
                            // 1. Пользователь найден
                            // 2. Это не ОСО (ID 129)
                            // 3. Это не основной исполнитель
                            // 4. Это не пользователь по умолчанию (ID 627)
                            if ($userId && $userId != 129 && $userId != $responsibleId && $userId != 627) {
                                $accomplices[] = $userId;
                            }
                        }
                    }
                }
                
                // Проверяем assignees на наличие дополнительных пользователей для соисполнителей
                if (!empty($taskData['assignees'])) {
                    $assigneeList = explode(',', $taskData['assignees']);
                    foreach ($assigneeList as $assignee) {
                        $assignee = trim($assignee);
                        if (!empty($assignee)) {
                            $userId = $this->findUserIdByName($assignee);
                            // Добавляем в соисполнители только если:
                            // 1. Пользователь найден
                            // 2. Это не ОСО (ID 129)
                            // 3. Это не основной исполнитель
                            // 4. Это не пользователь по умолчанию (ID 627)
                            if ($userId && $userId != 129 && $userId != $responsibleId && $userId != 627) {
                                $accomplices[] = $userId;
                            }
                        }
                    }
                }
                
                // Убираем дубликаты из соисполнителей
                $accomplices = array_unique($accomplices);

                // 5. Подготавливаем поля для обновления
                $updateFields = [];
                
                // Обновляем исполнителя
                $updateFields['RESPONSIBLE_ID'] = $responsibleId;
                if ($responsibleId == 129) {
                    $updateFields['FLOW_ID'] = 23;
                }
                $updateFields['UF_AUTO_224814238444'] = $taskData['assignees'] ?? '';
                
                // Обновляем постановщика если есть
                if ($creatorId && $creatorId != 627) {
                    $updateFields['CREATED_BY'] = $creatorId;
                }
                $updateFields['UF_AUTO_698832243310'] = $taskData['creator'] ?? '';
                $updateFields['UF_AUTO_777657568110'] = $taskData['participants'] ?? '';
                
                // Обновляем соисполнителей если они есть (исключаем пользователя 627)
                if (!empty($accomplices)) {
                    $updateFields['ACCOMPLICES'] = array_values($accomplices);
                } else {
                    // Если соисполнителей нет, очищаем поле
                    $updateFields['ACCOMPLICES'] = [];
                }
                
                // 6. Добавляем задачу в батч для обновления
                $batchUpdates[] = [
                    'index' => $index,
                    'taskId' => $bitrixTask['id'],
                    'fields' => $updateFields,
                    'data' => [
                        'task_id' => $taskData['id'],
                        'bitrix_task_id' => $bitrixTask['id'],
                        'responsible_id' => $responsibleId,
                        'responsible_name' => $responsibleName,
                        'responsible_found_by' => $foundBy,
                        'creator_name' => $taskData['creator'] ?? '',
                        'accomplices_count' => count($accomplices),
                        'accomplices_ids' => $accomplices
                    ]
                ];

                // Если накопили достаточно задач или это последняя задача
                if (count($batchUpdates) >= 50 || $index === count($this->jsonData) - 1) {
                    echo "Выполняем пакетное обновление " . count($batchUpdates) . " задач...\n";
                    
                    $batchResults = $this->updateTasksBatch($batchUpdates);
                    foreach ($batchUpdates as $batchItem) {
                        $batchIndex = $batchItem['index'];
                        
                        if (isset($batchResults[$batchIndex]) && !empty($batchResults[$batchIndex])) {
                            $results[] = array_merge($batchItem['data'], [
                                'status' => 'success',
                                'updated_fields' => array_keys($batchItem['fields'])
                            ]);
                            echo "✓ Задача #" . ($batchIndex + 1) . " обновлена успешно\n";
                        } else {
                            $results[] = array_merge($batchItem['data'], [
                                'status' => 'error',
                                'message' => 'Ошибка при пакетном обновлении задачи'
                            ]);
                            echo "✗ Ошибка при обновлении задачи #" . ($batchIndex + 1) . "\n";
                        }
                        
                        // Обновляем счетчик
                        file_put_contents($counterFile, $batchIndex + 1);
                    }
                    
                    // Очищаем батч
                    $batchUpdates = [];
                    
                    // Пауза между батчами
                    usleep(2000000); // 2 секунды
                }
                
            } catch (Exception $e) {
                $results[] = [
                    'task_id' => $taskData['id'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                echo "✗ Ошибка: " . $e->getMessage() . "\n";
            }
        }
        
        return $results;
    }
}

try {
    // Конфигурация
    $jsonFilePath = 'tasks.json'; // путь к вашему JSON файлу
    $resultsFile = 'import_results.json'; // файл для сохранения результатов
    
    // Создаем импортер и обрабатываем задачи
    $importer = new BitrixTaskImporter($jsonFilePath);
    $results = $importer->processTasks();
    
    // Сохраняем результаты
    if (!empty($resultsFile)) {
        file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Результаты сохранены в: $resultsFile\n";
    }
    
    // Статистика
    $successCount = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
    $errorCount = count(array_filter($results, function($r) { return $r['status'] === 'error'; }));
    
    echo "\n=== СТАТИСТИКА ===\n";
    echo "Успешно: $successCount\n";
    echo "С ошибками: $errorCount\n";
    echo "Всего: " . count($results) . "\n";
    
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . "\n";
}