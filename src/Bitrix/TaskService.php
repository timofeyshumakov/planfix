<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Config;
use App\Runtime;
use App\Support\Dates;
use App\Support\Html;
use App\Support\Logger;
use CRest;
use Exception;

final class TaskService
{
    public static function createTask($taskData)
    {
        try {
            $defaultUserId = Config::defaultBitrixUserId();

            $statusMap = [
                3 => 5,
                2 => 3,
                1 => 2,
            ];

            $planfixStatus = $taskData['status']['id'] ?? 5;
            $bitrixStatus = $statusMap[$planfixStatus] ?? 5;

            $fields = [
                'TITLE' => $taskData['name'] ?? 'Без названия',
                'DESCRIPTION' => Html::toPlainText($taskData['description'] ?? ''),
                'RESPONSIBLE_ID' => $taskData['responsible_bitrix_id'] ?? $defaultUserId,
                'CREATED_BY' => $taskData['creator_bitrix_id'] ?? $defaultUserId,
                'STATUS' => $bitrixStatus,
            ];

            if (!empty($taskData['responsible_text'])) {
                $fields['UF_AUTO_224814238444'] = $taskData['responsible_text'];
            } else {
                $fields['UF_AUTO_224814238444'] = 'Не указан';
            }

            if (!empty($taskData['creator_text'])) {
                $fields['UF_AUTO_698832243310'] = $taskData['creator_text'];
            } else {
                $fields['UF_AUTO_698832243310'] = 'Не указан';
            }

            if (!empty($taskData['accomplices_text'])) {
                $fields['UF_AUTO_УЧАСТНИКИ_ТЕКСТ'] = $taskData['accomplices_text'];
            } else {
                $fields['UF_AUTO_УЧАСТНИКИ_ТЕКСТ'] = 'Не указаны';
            }

            $startDatePlan = null;
            if (!empty($taskData['startDateTime'])) {
                $startDatePlan = substr($taskData['startDateTime']['datetime'], 0, -1);
                $fields['START_DATE_PLAN'] = $startDatePlan;
            }

            if (!empty($taskData['endDateTime'])) {
                $endDatePlan = str_replace('T', ' ', substr($taskData['endDateTime']['datetime'], 0, -1));

                if ($taskData['status']['id'] == 3 || $taskData['isCompleted']) {
                    if (Dates::isValidEndDate($startDatePlan, $endDatePlan)) {
                        $fields['END_DATE_PLAN'] = $endDatePlan;
                    } else {
                        Logger::logError('Invalid dates: END_DATE_PLAN <= START_DATE_PLAN. Using DEADLINE instead.', [
                            'planfix_id' => $taskData['planfix_id'] ?? 'unknown',
                            'name' => $taskData['name'] ?? 'unknown',
                            'start_date' => $startDatePlan,
                            'end_date' => $endDatePlan,
                        ]);
                        $fields['DEADLINE'] = $endDatePlan;
                    }
                } else {
                    $fields['DEADLINE'] = $endDatePlan;
                }
            }

            if (!empty($taskData['dateTime'])) {
                $fields['CREATED_DATE'] = substr($taskData['dateTime']['datetime'], 0, -1);
            }

            if (!empty($taskData['deal_bitrix_id'])) {
                $fields['UF_CRM_TASK'] = ['D_' . $taskData['deal_bitrix_id']];
            }

            if (!empty($taskData['deal_bitrix_id'])) {
                $fields['UF_AUTO_733499486508'] = $taskData['deal_bitrix_id'];
            }

            if (!empty($taskData['accomplices_bitrix_ids'])) {
                $fields['ACCOMPLICES'] = $taskData['accomplices_bitrix_ids'];
            }

            if (!empty($taskData['auditors_bitrix_ids'])) {
                $fields['AUDITORS'] = $taskData['auditors_bitrix_ids'];
            }

            if (!empty($taskData['planfix_id'])) {
                $fields['UF_AUTO_127801401239'] = $taskData['planfix_id'];
            }

            $result = CRest::call('tasks.task.add', [
                'fields' => $fields,
            ]);

            if (isset($result['error'])) {
                Logger::logError('Ошибка создания задачи: ' . $result['error_description'], [
                    'task_name' => $taskData['name'] ?? 'Без названия',
                    'planfix_id' => $taskData['planfix_id'] ?? null,
                ]);
                return null;
            }

            return $result['result']['task']['id'];
        } catch (Exception $e) {
            Logger::logError('Исключение при создании задачи: ' . $e->getMessage(), [
                'task_data' => $taskData,
            ]);
            return null;
        }
    }

    public static function createTasksBatch($tasksData): array
    {
        $batchSize = Runtime::$batchSize;
        $flowMappings = [
            'Отдел технического сопровождения (ОТС)' => 23,
        ];
        $defaultUserId = Config::defaultBitrixUserId();

        try {
            if (empty($tasksData)) {
                return [];
            }

            $chunks = array_chunk($tasksData, $batchSize);
            $allResults = [];
            $chunkIndex = 1;
            $totalChunks = count($chunks);

            foreach ($chunks as $chunk) {
                try {
                    echo "\nОбработка пакета {$chunkIndex} из {$totalChunks}...\n";

                    $batchCommands = [];
                    $taskIndexMap = [];

                    foreach ($chunk as $index => $taskData) {
                        try {
                            $cmdKey = "add_task_{$index}";

                            $statusMap = [
                                3 => 5,
                                2 => 3,
                                1 => 2,
                            ];

                            $planfixStatus = $taskData['status']['id'] ?? 5;
                            $bitrixStatus = $statusMap[$planfixStatus] ?? 5;

                            $fields = [
                                'TITLE' => $taskData['name'] ?? 'Без названия',
                                'DESCRIPTION' => Html::toPlainText($taskData['description'] ?? ''),
                                'RESPONSIBLE_ID' => $taskData['responsible_bitrix_id'] ?? $defaultUserId,
                                'CREATED_BY' => $taskData['creator_bitrix_id'] ?? $defaultUserId,
                                'STATUS' => $bitrixStatus,
                            ];

                            if (!empty($taskData['responsible_text'])) {
                                $responsibleName = trim($taskData['responsible_text']);
                                /*
                                foreach ($flowMappings as $departmentName => $flowId) {
                                    if (stripos($responsibleName, $departmentName) !== false) {
                                        $fields['FLOW_ID'] = $flowId;
                                        echo "Задача '{$taskData['name']}' добавлена в поток: {$departmentName} (FLOW_ID: {$flowId})\n";
                                        break;
                                    }
                                }
                                */
                            }

                            if (!empty($taskData['responsible_text'])) {
                                $fields['UF_AUTO_224814238444'] = $taskData['responsible_text'];
                            } else {
                                $fields['UF_AUTO_224814238444'] = '';
                            }

                            if (!empty($taskData['creator_text'])) {
                                $fields['UF_AUTO_698832243310'] = $taskData['creator_text'];
                            } else {
                                $fields['UF_AUTO_698832243310'] = '';
                            }

                            if (!empty($taskData['accomplices_text'])) {
                                $fields['UF_AUTO_777657568110'] = $taskData['accomplices_text'];
                            } else {
                                $fields['UF_AUTO_777657568110'] = '';
                            }

                            $startDatePlan = null;
                            if (!empty($taskData['startDateTime'])) {
                                $startDatePlan = substr($taskData['startDateTime']['datetime'], 0, -1);
                                $fields['START_DATE_PLAN'] = $startDatePlan;
                            }

                            if (!empty($taskData['endDateTime'])) {
                                $endDatePlan = str_replace('T', ' ', substr($taskData['endDateTime']['datetime'], 0, -1));

                                if ($taskData['status']['id'] == 3 || $taskData['isCompleted']) {
                                    if (Dates::isValidEndDate($startDatePlan, $endDatePlan)) {
                                        $fields['END_DATE_PLAN'] = $endDatePlan;
                                    } else {
                                        Logger::logError('Invalid dates: END_DATE_PLAN <= START_DATE_PLAN. Using DEADLINE instead.', [
                                            'planfix_id' => $taskData['planfix_id'] ?? 'unknown',
                                            'name' => $taskData['name'] ?? 'unknown',
                                            'start_date' => $startDatePlan,
                                            'end_date' => $endDatePlan,
                                        ]);
                                        $fields['DEADLINE'] = $endDatePlan;
                                    }
                                } else {
                                    $fields['DEADLINE'] = $endDatePlan;
                                }
                            }

                            if (!empty($taskData['dateTime'])) {
                                $fields['CREATED_DATE'] = substr($taskData['dateTime']['datetime'], 0, -1);
                            }

                            if (!empty($taskData['deal_bitrix_id'])) {
                                $fields['UF_CRM_TASK'] = ['D_' . $taskData['deal_bitrix_id']];
                            }

                            if (!empty($taskData['accomplices_bitrix_ids'])) {
                                $fields['ACCOMPLICES'] = $taskData['accomplices_bitrix_ids'];
                            }

                            if (!empty($taskData['auditors_bitrix_ids'])) {
                                $fields['AUDITORS'] = $taskData['auditors_bitrix_ids'];
                            }

                            if (!empty($taskData['planfix_id'])) {
                                $fields['UF_AUTO_127801401239'] = $taskData['planfix_id'];
                            }

                            if (!empty($taskData['parent'])) {
                                $fields['UF_AUTO_968303778946'] = $taskData['parent'];
                            }

                            $batchCommands[$cmdKey] = [
                                'method' => 'tasks.task.add',
                                'params' => ['fields' => $fields],
                            ];

                            $taskIndexMap[$cmdKey] = [
                                'planfix_id' => $taskData['planfix_id'],
                                'name' => $taskData['name'],
                                'original_index' => $index,
                                'responsible_text' => $taskData['responsible_text'] ?? '',
                                'creator_text' => $taskData['creator_text'] ?? '',
                                'accomplices_text' => $taskData['accomplices_text'] ?? '',
                            ];
                        } catch (Exception $e) {
                            Logger::logError('Исключение при подготовке задачи для batch: ' . $e->getMessage(), [
                                'task_index' => $index,
                            ]);
                            continue;
                        }
                    }

                    if (empty($batchCommands)) {
                        echo "Пакет {$chunkIndex} пуст, пропускаем...\n";
                        $chunkIndex++;
                        continue;
                    }

                    $maxRetries = 10;
                    $retryDelay = 120;
                    $batchResult = null;
                    $attempt = 1;

                    do {
                        echo "\nПопытка {$attempt} из {$maxRetries} для пакета {$chunkIndex}...\n";

                        $batchResult = CRest::callBatch($batchCommands, false);

                        $hasTimeLimitError = false;

                        if (isset($batchResult['error'])) {
                            Logger::logError(
                                "Ошибка batch-запроса (попытка {$attempt}): "
                                . ($batchResult['error_description'] ?? 'Unknown error'),
                                [
                                    'chunk_index' => $chunkIndex,
                                    'attempt' => $attempt,
                                ]
                            );

                            if (strpos($batchResult['error'] ?? '', 'OPERATION_TIME_LIMIT') !== false
                                || strpos($batchResult['error_description'] ?? '', 'Method is blocked due to operation time limit') !== false) {
                                $hasTimeLimitError = true;
                            }
                        } elseif (isset($batchResult['result']['result_error'])) {
                            foreach ($batchResult['result']['result_error'] as $cmdKey => $error) {
                                if (isset($error['error']) && $error['error'] === 'OPERATION_TIME_LIMIT') {
                                    $hasTimeLimitError = true;
                                    Logger::logError(
                                        "OPERATION_TIME_LIMIT ошибка в команде {$cmdKey} (попытка {$attempt}): "
                                        . $error['error_description'],
                                        [
                                            'cmd_key' => $cmdKey,
                                            'chunk_index' => $chunkIndex,
                                            'task_name' => $taskIndexMap[$cmdKey]['name'] ?? 'unknown',
                                        ]
                                    );
                                    break;
                                }
                            }
                        }

                        if ($hasTimeLimitError && $attempt < $maxRetries) {
                            echo "Обнаружено ограничение времени выполнения. Ожидание {$retryDelay} секунд...\n";
                            sleep($retryDelay);
                            $attempt++;
                            $retryDelay = 120;
                        } else {
                            break;
                        }
                    } while ($attempt <= $maxRetries);

                    if ($hasTimeLimitError && $attempt > $maxRetries) {
                        Logger::logError(
                            "Превышено максимальное количество попыток ({$maxRetries}) для пакета {$chunkIndex} из-за OPERATION_TIME_LIMIT",
                            [
                                'chunk_index' => $chunkIndex,
                                'tasks_count' => count($batchCommands),
                            ]
                        );

                        echo "Используем fallback: создание задач по одной...\n";
                        foreach ($chunk as $taskData) {
                            try {
                                $taskResult = self::createTask($taskData);
                                if ($taskResult) {
                                    $allResults[] = [
                                        'planfix_id' => $taskData['planfix_id'],
                                        'bitrix_id' => $taskResult,
                                        'name' => $taskData['name'],
                                    ];
                                }
                            } catch (Exception $e) {
                                Logger::logError('Исключение при создании задачи по одной (fallback): ' . $e->getMessage(), [
                                    'planfix_id' => $taskData['planfix_id'] ?? 'unknown',
                                ]);
                                continue;
                            }
                        }
                    } elseif (isset($batchResult['error']) && !$hasTimeLimitError) {
                        Logger::logError(
                            'Ошибка batch-запроса после всех попыток: '
                            . ($batchResult['error_description'] ?? 'Unknown error')
                        );

                        foreach ($chunk as $taskData) {
                            try {
                                $taskResult = self::createTask($taskData);
                                if ($taskResult) {
                                    $allResults[] = [
                                        'planfix_id' => $taskData['planfix_id'],
                                        'bitrix_id' => $taskResult,
                                        'name' => $taskData['name'],
                                    ];
                                }
                            } catch (Exception $e) {
                                Logger::logError('Исключение при создании задачи по одной: ' . $e->getMessage(), [
                                    'planfix_id' => $taskData['planfix_id'] ?? 'unknown',
                                ]);
                                continue;
                            }
                        }
                    } else {
                        foreach ($batchCommands as $cmdKey => $command) {
                            try {
                                if (isset($batchResult['result']['result'][$cmdKey])) {
                                    $taskResult = $batchResult['result']['result'][$cmdKey];

                                    if (isset($taskResult['error'])) {
                                        Logger::logError(
                                            'Ошибка создания задачи в batch: '
                                            . $taskResult['error_description'],
                                            [
                                                'task_name' => $taskIndexMap[$cmdKey]['name'],
                                            ]
                                        );

                                        $taskData = $chunk[$taskIndexMap[$cmdKey]['original_index']] ?? null;
                                        if ($taskData) {
                                            $singleResult = self::createTask($taskData);
                                            if ($singleResult) {
                                                $allResults[] = [
                                                    'planfix_id' => $taskData['planfix_id'],
                                                    'bitrix_id' => $singleResult,
                                                    'name' => $taskData['name'],
                                                ];
                                            }
                                        }
                                    } else {
                                        $taskId = $taskResult['task']['id'] ?? null;

                                        if ($taskId) {
                                            $allResults[] = [
                                                'planfix_id' => $taskIndexMap[$cmdKey]['planfix_id'],
                                                'bitrix_id' => $taskId,
                                                'name' => $taskIndexMap[$cmdKey]['name'],
                                            ];
                                        }
                                    }
                                } elseif (isset($batchResult['result']['result_error'][$cmdKey])) {
                                    $error = $batchResult['result']['result_error'][$cmdKey];
                                    $errorDescription = $error['error_description'] ?? '';

                                    if (strpos($errorDescription, 'дата окончания меньшая даты старта') !== false) {
                                        Logger::logError('КРИТИЧЕСКАЯ ОШИБКА: Обнаружена задача с некорректными датами. Перенос прерван.', [
                                            'task_name' => $taskIndexMap[$cmdKey]['name'],
                                            'planfix_id' => $taskIndexMap[$cmdKey]['planfix_id'],
                                            'error' => $errorDescription,
                                        ]);
                                    }
                                    Logger::logError(
                                        'Ошибка создания задачи в result_error: '
                                        . ($error['error_description'] ?? 'Unknown error'),
                                        [
                                            'task_name' => $taskIndexMap[$cmdKey]['name'],
                                            'error_code' => $error['error'] ?? 'unknown',
                                        ]
                                    );

                                    $taskData = $chunk[$taskIndexMap[$cmdKey]['original_index']] ?? null;
                                    if ($taskData) {
                                        $singleResult = self::createTask($taskData);
                                        if ($singleResult) {
                                            $allResults[] = [
                                                'planfix_id' => $taskData['planfix_id'],
                                                'bitrix_id' => $singleResult,
                                                'name' => $taskData['name'],
                                            ];
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                Logger::logError('Исключение при обработке результата batch: ' . $e->getMessage(), [
                                    'cmd_key' => $cmdKey,
                                ]);
                                continue;
                            }
                        }
                    }

                    $chunkIndex++;

                    echo "Ожидание 5 секунд перед следующим пакетом...\n";
                    sleep(5);
                } catch (Exception $e) {
                    Logger::logError('Исключение при обработке пакета задач: ' . $e->getMessage(), [
                        'chunk_index' => $chunkIndex,
                    ]);

                    foreach ($chunk as $taskData) {
                        try {
                            $taskResult = self::createTask($taskData);
                            if ($taskResult) {
                                $allResults[] = [
                                    'planfix_id' => $taskData['planfix_id'],
                                    'bitrix_id' => $taskResult,
                                    'name' => $taskData['name'],
                                ];
                            }
                        } catch (Exception $innerE) {
                            Logger::logError('Исключение при создании задачи по одной (после ошибки пакета): ' . $innerE->getMessage(), [
                                'planfix_id' => $taskData['planfix_id'] ?? 'unknown',
                            ]);
                            continue;
                        }
                    }

                    $chunkIndex++;
                    continue;
                }
            }

            return $allResults;
        } catch (Exception $e) {
            Logger::logError('Критическое исключение при пакетном создании задач: ' . $e->getMessage());
            return [];
        }
    }

    public static function findTasksByPlanfixIds(array $planfixIds): array
    {
        if (empty($planfixIds)) {
            return [];
        }
        $map = [];
        $chunks = array_chunk(array_unique($planfixIds), 50);
        foreach ($chunks as $chunk) {
            try {
                $result = CRest::call('tasks.task.list', [
                    'filter' => [
                        'UF_AUTO_127801401239' => $chunk,
                    ],
                    'select' => ['ID', 'UF_AUTO_127801401239'],
                ]);
                if (isset($result['error'])) {
                    Logger::logError('Batch lookup error: ' . $result['error_description'], [
                        'chunk_size' => count($chunk),
                        'chunk_sample' => array_slice($chunk, 0, 3),
                    ]);
                    continue;
                }
                foreach ($result['result']['tasks'] ?? [] as $task) {
                    $pfId = $task['ufAuto127801401239'];
                    $map[(string) $pfId] = $task['id'];
                    Logger::logError('Existing task found: Planfix %s -> Bitrix %d', [$pfId, $task['id']], 'INFO');
                }
            } catch (Exception $e) {
                Logger::logError('Exception in batch chunk: ' . $e->getMessage(), ['chunk_size' => count($chunk)]);
            }
        }
        return $map;
    }

    public static function findTaskByPlanfixId($planfixId)
    {
        if (empty($planfixId)) {
            return null;
        }
        $map = self::findTasksByPlanfixIds([$planfixId]);
        return $map[(string) $planfixId] ?? null;
    }
}
