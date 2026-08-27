<?php
require_once(__DIR__ . '/crest.php');

class BitrixTaskImporter
{
    private $jsonData;
    
    public function __construct($jsonFilePath)
    {
        $jsonContent = file_get_contents($jsonFilePath);
        $this->jsonData = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
        }
    }
    
    /**
     * Поиск пользователя в Битрикс24 по имени и фамилии
     */
    private function findUserIdByName($fullName)
    {
        if (empty($fullName)) {
            return null;
        }
        
        // Разделяем имя и фамилию (предполагаем формат "Имя Фамилия")
        $nameParts = explode(' ', trim($fullName));
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        
        $result = CRest::call('user.search', [
            'FILTER' => [
                'NAME' => $firstName,
                'LAST_NAME' => $lastName
            ]
        ]);
        
        if (isset($result['result']) && !empty($result['result'])) {
            return $result['result'][0]['ID'];
        }

        return null;
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
     * Обновление задачи в Битрикс24
     */
    private function updateTask($taskId, $fields)
    {
        $result = CRest::call('tasks.task.update', [
            'taskId' => $taskId,
            'fields' => $fields
        ]);
                    print_r($fields);
        return $result;
    }
    
    /**
     * Определение исполнителя по приоритету
     */
    private function determineResponsible($taskData)
    {
        $responsibleId = null;
        $responsibleName = '';
        $foundBy = '';
        
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
            $responsibleId = $this->findUserIdByName($taskData['creator']);
            if ($responsibleId) {
                $responsibleName = $taskData['creator'];
                $foundBy = 'creator';
                return [$responsibleId, $responsibleName, $foundBy];
            }
        }
        
        // 4. Если постановщик не найден, ставим пользователя с ID 627
        $responsibleId = 627;
        $responsibleName = 'Пользователь ID 627 (по умолчанию)';
        $foundBy = 'default';
        
        return [$responsibleId, $responsibleName, $foundBy];
    }
    
    /**
     * Основной метод для обработки всех задач из JSON
     */
    public function processTasks()
    {
        $results = [];
        
        foreach ($this->jsonData as $index => $taskData) {
            try {
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
                            if ($userId && $userId != $responsibleId) {
                                $accomplices[] = $userId;
                            }
                        }
                    }
                }
                
                // 5. Подготавливаем поля для обновления
                $updateFields = [];
                
                // Обновляем исполнителя
                $updateFields['RESPONSIBLE_ID'] = $responsibleId;
                $updateFields['UF_AUTO_224814238444'] = $responsibleName;
                
                // Обновляем постановщика если есть
                if ($creatorId) {
                    $updateFields['UF_AUTO_698832243310'] = $taskData['creator'] ?? '';
                    $updateFields['CREATED_BY'] = $creatorId;
                }
                
                // Обновляем соисполнителей если они есть
                if (!empty($accomplices)) {
                    $updateFields['ACCOMPLICES'] = $accomplices;
                }

                // 6. Обновляем задачу
                if (!empty($updateFields)) {
                    $updateResult = $this->updateTask($bitrixTask['id'], $updateFields);

                    if (isset($updateResult['result'])) {
                        $results[] = [
                            'task_id' => $taskData['id'],
                            'bitrix_task_id' => $bitrixTask['id'],
                            'status' => 'success',
                            'updated_fields' => array_keys($updateFields),
                            'responsible_id' => $responsibleId,
                            'responsible_name' => $responsibleName,
                            'responsible_found_by' => $foundBy,
                            'creator_name' => $taskData['creator'] ?? '',
                            'accomplices_count' => count($accomplices)
                        ];
                        echo "✓ Задача обновлена успешно (исполнитель найден через: {$foundBy})\n";
                    } else {
                        $results[] = [
                            'task_id' => $taskData['id'],
                            'status' => 'error',
                            'message' => 'Ошибка при обновлении задачи',
                            'error' => $updateResult
                        ];
                        echo "✗ Ошибка при обновлении задачи\n";
                    }
                } else {
                    $results[] = [
                        'task_id' => $taskData['id'],
                        'status' => 'skipped',
                        'message' => 'Нет данных для обновления'
                    ];
                    echo "✓ Нет данных для обновления\n";
                }
                
            } catch (Exception $e) {
                $results[] = [
                    'task_id' => $taskData['id'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                echo "✗ Ошибка: " . $e->getMessage() . "\n";
            }
            
            // Пауза между запросами чтобы не превысить лимиты API
            usleep(200000); // 0.5 секунды
        }
        
        return $results;
    }
    
    /**
     * Сохранение результатов обработки в файл
     */
    public function saveResults($results, $outputFile)
    {
        file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Результаты сохранены в: $outputFile\n";
    }
}

// Пример использования
try {
    // Конфигурация
    $jsonFilePath = 'tasks.json'; // путь к вашему JSON файлу
    $resultsFile = 'import_results.json'; // файл для сохранения результатов
    
    // Создаем импортер и обрабатываем задачи
    $importer = new BitrixTaskImporter($jsonFilePath);
    $results = $importer->processTasks();
    
    // Сохраняем результаты
    $importer->saveResults($results, $resultsFile);
    
    // Статистика
    $successCount = count(array_filter($results, fn($r) => $r['status'] === 'success'));
    $errorCount = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    $skippedCount = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
    
    // Статистика по способам определения исполнителя
    $foundByAssignees = count(array_filter($results, 
        fn($r) => $r['status'] === 'success' && isset($r['responsible_found_by']) && $r['responsible_found_by'] === 'assignees'));
    $foundByParticipants = count(array_filter($results, 
        fn($r) => $r['status'] === 'success' && isset($r['responsible_found_by']) && $r['responsible_found_by'] === 'participants'));
    $foundByCreator = count(array_filter($results, 
        fn($r) => $r['status'] === 'success' && isset($r['responsible_found_by']) && $r['responsible_found_by'] === 'creator'));
    $foundByDefault = count(array_filter($results, 
        fn($r) => $r['status'] === 'success' && isset($r['responsible_found_by']) && $r['responsible_found_by'] === 'default'));
    
    echo "\n======= СТАТИСТИКА =======\n";
    echo "Успешно: $successCount\n";
    echo "С ошибками: $errorCount\n";
    echo "Пропущено: $skippedCount\n";
    echo "Всего обработано: " . count($results) . "\n";
    
    echo "\n======= СПОСОБЫ НАЗНАЧЕНИЯ ИСПОЛНИТЕЛЯ =======\n";
    echo "Назначенный исполнитель (assignees): $foundByAssignees\n";
    echo "Первый участник (participants): $foundByParticipants\n";
    echo "Постановщик (creator): $foundByCreator\n";
    echo "По умолчанию (ID 627): $foundByDefault\n";
    
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . "\n";
}