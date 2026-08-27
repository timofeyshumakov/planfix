<?php
// Настройки
$batch_size = 50; // Размер пакета для обновления
require_once('crest.php');
// Функция для получения задач без родителя
function getTasksWithoutParent($start = 0) {
    return CRest::call('tasks.task.list', [
        'filter' => [
            '!UF_AUTO_968303778946' => false, // Поле не пустое
            'PARENT_ID' => null, // Только задачи без родителя
        ],
        'select' => [
            'ID',
            'UF_AUTO_968303778946', // Planfix parent ID
            'PARENT_ID'
        ],
        'start' => $start,
        'order' => ['ID' => 'ASC']
    ]);
}

// Функция для обновления родительских задач
function updateParentTasks($tasksToUpdate) {
    $batchCommands = [];
    $i = 0;
    foreach ($tasksToUpdate as $taskId => $parentId) {
        $batchCommands['t' . $i] = [
            'method' => 'tasks.task.update',
            'params' => [
                'taskId' => $taskId,
                'fields' => [
                    'PARENT_ID' => $parentId
                ]
            ]
        ];
        $i++;
    }

    return CRest::callBatch(
        $batchCommands
    );
}

// Основной процесс
try {
    $start = 0;
    $processedCount = 0;
    
    while (true) {
        // Получаем задачи без родителя
        
        $tasksResult = getTasksWithoutParent($start);
                    print_r($tasksResult);
        if (!$tasksResult || empty($tasksResult['result']) || empty($tasksResult['result']['tasks'])) {
            echo "Все задачи обработаны или произошла ошибка\n";
            break;
        }
        
        $tasks = $tasksResult['result']['tasks'];
        $total = $tasksResult['result']['total'];

        // Собираем Planfix ID для поиска
        $planfixIds = [];
        $taskMap = []; // Сопоставление Planfix ID -> Битрикс задачи
        print_r('xqreswfds');

        foreach ($tasks as $task) {
            $planfixId = $task['ufAuto968303778946'];
            $planfixIds[] = $planfixId;
            $taskMap[$planfixId] = $task['id'];

        }

        // Ищем задачи с указанными Planfix ID
        $parentTasksResult = CRest::call('tasks.task.list', [
            'filter' => [
                'UF_AUTO_127801401239' => $planfixIds
            ],
            'select' => [
                'id',
                'UF_AUTO_127801401239' // Planfix ID
            ],
            'order' => ['ID' => 'ASC']
        ]);
                    
        if ($parentTasksResult && !empty($parentTasksResult['result']['tasks'])) {
            $parentTasks = $parentTasksResult['result']['tasks'];
            
            // Создаем массив для обновления: childTaskId => parentTaskId
            $tasksToUpdate = [];
            $planfixToB24Id = [];

            // Создаем карту Planfix ID -> Битрикс ID для родительских задач
            foreach ($parentTasks as $parentTask) {
                $parentPlanfixId = $parentTask['ufAuto127801401239'];
                $planfixToB24Id[$parentPlanfixId] = $parentTask['id'];
            }
            
            // Находим соответствия и готовим обновления
            foreach ($taskMap as $planfixId => $childTaskId) {
                if (isset($planfixToB24Id[$planfixId])) {
                    $parentTaskId = $planfixToB24Id[$planfixId];
                    $tasksToUpdate[$childTaskId] = $parentTaskId;
                }
            }
print_r($tasksToUpdate);
            // Обновляем задачи пакетами по 50
            if (!empty($tasksToUpdate)) {
                $chunks = array_chunk($tasksToUpdate, $batch_size, true);

                foreach ($chunks as $chunk) {
                    $updateResult = updateParentTasks($chunk);
                    if ($updateResult && isset($updateResult['result']['result'])) {
                        $results = $updateResult['result']['result'];
                        $successCount = count(array_filter($results));
                        echo "Обновлено {$successCount} задач\n";
                        $processedCount += $successCount;
                    } else {
                        echo "Ошибка при обновлении пакета задач\n";
                    }
                    
                    // Небольшая пауза между пакетами
                    sleep(0.25);
                }
            }
        }
        
        $start += count($tasks);
        echo $start;
        echo $total;
        echo '$start';
        // Если обработали все задачи, выходим
        if ($start >= $total) {
            echo "Обработка завершена. Всего обновлено задач: {$processedCount}\n";
        }
        
        echo "Обработано {$start} из {$total} задач. Обновлено родительских связей: {$processedCount}\n";

    }
    
} catch (Exception $e) {
    echo "Произошла ошибка: " . $e->getMessage() . "\n";
}
?>