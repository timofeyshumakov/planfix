<?php

declare(strict_types=1);

namespace App\Migration;

use App\Bitrix\ChecklistService;
use App\Bitrix\CommentService;
use App\Bitrix\DealService;
use App\Bitrix\FileUploader;
use App\Bitrix\TaskService;
use App\Config;
use App\Mapping\UserMapping;
use App\Planfix\TaskApi;
use App\Runtime;
use App\Support\Logger;
use Exception;

final class TransferService
{
    public static function transferCompletedTasks(int $currentOffset = 0, bool $testMode = false): array
    {
        try {
            $baseUrl = Config::planfixBaseUrl();
            $apiToken = Config::planfixApiToken();
            $tasksPerIteration = Runtime::$tasksPerIteration;
            $userMapping = Runtime::$userMapping;

            $createdTasks = [];
            $tasksToCreate = [];

            $tasksRequestData = [
                'pageSize' => $tasksPerIteration,
                'offset' => $currentOffset,
            ];
            if ($testMode && Runtime::$testTaskId !== null) {
                $tasksRequestData['filters'] = [[
                    'type' => 10,
                    'operator' => 'equal',
                    'value' => Runtime::$testTaskId,
                ]];
            }
            $tasksRequestData['fields'] = 'id, name, description, additionalDescriptionData, priority, status, processId, resultChecking, type, assigner, parent, object, template, project, counterparty, dateTime, startDateTime, endDateTime, hasStartDate, hasEndDate, hasStartTime, hasEndTime, delayedTillDate, actualCompletionDate, dateOfLastUpdate, duration, durationUnit, durationType, overdue, closeToDeadLine, notAcceptedInTime, inFavorites, isSummary, isSequential, assignees, participants, auditors, recurrence, isDeleted, files, dataTags, sourceObjectId, sourceDataVersion';

            $tasksResult = TaskApi::listTasks($tasksRequestData, $apiToken, $baseUrl);

            if (!$tasksResult['success']) {
                Logger::logError('Ошибка при получении задач из Planfix', [
                    'http_code' => $tasksResult['http_code'] ?? 'N/A',
                    'error' => $tasksResult['error'] ?? 'Unknown error',
                ]);
                return ['created' => 0, 'offset' => $currentOffset, 'tasks' => []];
            }

            if (!isset($tasksResult['data']['tasks'])) {
                echo "Ответ получен, но ключ 'tasks' не найден.\n";
                return ['created' => 0, 'offset' => $currentOffset, 'tasks' => []];
            }

            $tasks = $tasksResult['data']['tasks'];
            $totalTasks = count($tasks);
            echo "Успешно получено завершенных задач из Planfix: {$totalTasks}\n";

            if ($totalTasks === 0) {
                echo "Нет завершенных задач для переноса\n";
                return ['created' => 0, 'offset' => $currentOffset, 'tasks' => []];
            }

            $defaultUserId = Config::defaultBitrixUserId();

            foreach ($tasks as $index => $task) {
                try {
                    $allUsers = UserMapping::getAllTaskUsersWithDetails($task);

                    $responsibleBitrixId = null;
                    $responsibleNames = [];
                    $creatorBitrixId = null;
                    $creatorNames = [];
                    $accomplicesBitrixIds = [];
                    $accompliceNames = [];
                    $auditorsBitrixIds = [];

                    foreach ($allUsers as $user) {
                        try {
                            $bitrixId = UserMapping::findBitrixUser($user['name'], $userMapping);

                            switch ($user['type']) {
                                case 'creator':
                                    if (!empty($user['name'])) {
                                        $creatorNames[] = $user['name'];
                                    }
                                    if ($bitrixId && !$creatorBitrixId) {
                                        $creatorBitrixId = $bitrixId;
                                    }
                                    break;
                                case 'responsible':
                                case 'responsible_group':
                                    if (!empty($user['name'])) {
                                        $responsibleNames[] = $user['name'];
                                    }
                                    if ($bitrixId && !$responsibleBitrixId) {
                                        $responsibleBitrixId = $bitrixId;
                                    }
                                    break;
                                case 'accomplice':
                                case 'accomplice_group':
                                    if (!empty($user['name'])) {
                                        $accompliceNames[] = $user['name'];
                                    }
                                    if ($bitrixId) {
                                        $accomplicesBitrixIds[] = $bitrixId;
                                    }
                                    break;
                                case 'auditor':
                                case 'auditor_group':
                                    if ($bitrixId) {
                                        $auditorsBitrixIds[] = $bitrixId;
                                    }
                                    break;
                            }
                        } catch (Exception $e) {
                            Logger::logError('Исключение при обработке пользователя задачи: ' . $e->getMessage(), [
                                'user' => $user,
                                'task_id' => $task['id'] ?? 'unknown',
                            ]);
                            continue;
                        }
                    }

                    $responsibleNames = array_unique($responsibleNames);
                    $creatorNames = array_unique($creatorNames);
                    $accompliceNames = array_unique($accompliceNames);
                    $accomplicesBitrixIds = array_unique($accomplicesBitrixIds);
                    $auditorsBitrixIds = array_unique($auditorsBitrixIds);

                    $responsibleText = !empty($responsibleNames) ? implode(', ', $responsibleNames) : '';
                    $creatorText = !empty($creatorNames) ? implode(', ', $creatorNames) : '';
                    $accomplicesText = !empty($accompliceNames) ? implode(', ', $accompliceNames) : '';

                    if ($responsibleBitrixId && in_array($responsibleBitrixId, $accomplicesBitrixIds)) {
                        $accomplicesBitrixIds = array_diff($accomplicesBitrixIds, [$responsibleBitrixId]);
                    }

                    if (!$responsibleBitrixId && $creatorBitrixId) {
                        $responsibleBitrixId = $creatorBitrixId;
                        if (empty($responsibleText) && !empty($creatorText)) {
                            $responsibleText = $creatorText;
                        }
                    }

                    if (!$responsibleBitrixId) {
                        $responsibleBitrixId = $defaultUserId;
                    }

                    $dealBitrixId = null;
                    if (!empty($task['project']) && !empty($task['project']['id'])) {
                        try {
                            $projectId = $task['project']['id'];
                            $projectName = $task['project']['name'] ?? 'Проект ' . $projectId;
                            $dealBitrixId = DealService::createDeal($projectId, $projectName);
                        } catch (Exception $e) {
                            Logger::logError('Исключение при создании сделки: ' . $e->getMessage(), [
                                'project_id' => $task['project']['id'] ?? 'unknown',
                            ]);
                        }
                    }

                    $planfixStatusId = $task['status']['id'] ?? 5;
                    if ($planfixStatusId == 3) {
                        $endDateTimeValue = $task['actualCompletionDate'] ?? $task['endDateTime'] ?? null;
                    } else {
                        $endDateTimeValue = $task['endDateTime'] ?? null;
                    }

                    $taskData = [
                        'name' => $task['name'] ?? 'Без названия',
                        'description' => $task['description'] ?? '',
                        'status' => $task['status'] ?? 5,
                        'responsible_bitrix_id' => $responsibleBitrixId,
                        'responsible_text' => $responsibleText,
                        'creator_bitrix_id' => $creatorBitrixId,
                        'creator_text' => $creatorText,
                        'accomplices_text' => $accomplicesText,
                        'deal_bitrix_id' => $dealBitrixId,
                        'accomplices_bitrix_ids' => $accomplicesBitrixIds,
                        'auditors_bitrix_ids' => $auditorsBitrixIds,
                        'startDateTime' => $task['startDateTime'] ?? null,
                        'endDateTime' => $endDateTimeValue,
                        'isCompleted' => ($planfixStatusId == 3),
                        'dateTime' => $task['dateTime'] ?? null,
                        'planfix_id' => $task['id'] ?? null,
                        'parent' => $task['parent']['id'] ?? null,
                    ];

                    // Preserve original quirk: reset list each iteration
                    $tasksToCreate = [];
                    $tasksToCreate[] = $taskData;

                    $createdTasks[] = [
                        'planfix_id' => $task['id'],
                        'name' => $task['name'],
                        'status' => $task['status'] ?? null,
                        'responsible' => $responsibleNames,
                        'creator' => $creatorNames,
                        'accomplices_count' => count($accomplicesBitrixIds),
                        'auditors_count' => count($auditorsBitrixIds),
                        'task_data' => $taskData,
                    ];

                    Runtime::$executionStats['tasks_processed']++;
                    usleep(100000);
                } catch (Exception $e) {
                    Logger::logError('Исключение при обработке задачи: ' . $e->getMessage(), [
                        'task_index' => $index,
                        'task_id' => $task['id'] ?? 'unknown',
                    ]);
                    continue;
                }
            }

            $createdResults = [];
            if (!empty($tasksToCreate)) {
                $createdResults = TaskService::createTasksBatch($tasksToCreate);
                Runtime::$executionStats['tasks_created'] += count($createdResults);

                foreach ($createdTasks as &$createdTask) {
                    foreach ($createdResults as $result) {
                        if ($result['planfix_id'] == $createdTask['planfix_id']) {
                            $createdTask['bitrix_id'] = $result['bitrix_id'];
                            break;
                        }
                    }
                }
                unset($createdTask);

                $excludedTaskTitles = [
                    'Возникли ошибки при синхронизации услуг/пакетов с МГФ',
                    'Заявки тех.специалисту - Голосовая связь',
                    '',
                ];

                $ekomobilePatterns = [
                    '/^\[Экомобайл\] Новая заявка по номеру \d+$/u',
                    '/^\[Экомобайл\] Высокомаржинальный номер \d+ \("[^"]+", №\d+\)\.$/u',
                    '/^Новая активность "\d+ч скачок трафика"$/u',
                    '/^\[Экомобайл\]\. Заявка №\d+\. Вас подключили к работе\.$/u',
                ];

                foreach ($createdTasks as $task) {
                    try {
                        $skipExtra = in_array($task['name'], $excludedTaskTitles)
                            || array_reduce($ekomobilePatterns, function ($carry, $pattern) use ($task) {
                                return $carry || preg_match($pattern, $task['name'] ?? '');
                            }, false);

                        if (!empty($task['bitrix_id']) && !$skipExtra) {
                            $comments = TaskApi::getCommentsApi($task['planfix_id'], $apiToken, $baseUrl);
                            foreach ($comments as $comment) {
                                CommentService::createComment($task['bitrix_id'], $comment, $userMapping);
                            }

                            $checklist = TaskApi::getChecklist($task['planfix_id'], $apiToken, $baseUrl);
                            if (!empty($checklist)) {
                                ChecklistService::createChecklist($task['bitrix_id'], $checklist, $userMapping);
                            }

                            $statusId = $task['status']['id'] ?? ($task['task_data']['status']['id'] ?? null);
                            if ($statusId != 5) {
                                $taskFiles = TaskApi::getFiles($task['planfix_id'], $apiToken, $baseUrl);
                                if (!empty($taskFiles)) {
                                    FileUploader::processTaskFiles($task['bitrix_id'], $task['planfix_id'], $taskFiles);
                                } else {
                                    echo "  Нет файлов для задачи\n";
                                }
                            }

                            usleep(300000);
                        } else {
                            echo 'Пропущена дополнительная обработка для задачи: ' . ($task['name'] ?? 'без названия') . "\n";
                        }
                    } catch (Exception $e) {
                        Logger::logError('Исключение при дополнительной обработке задачи: ' . $e->getMessage(), [
                            'planfix_id' => $task['planfix_id'],
                        ]);
                        continue;
                    }
                }
            }

            return [
                'created' => count($createdResults),
                'offset' => $currentOffset + $totalTasks,
                'tasks' => $createdTasks,
            ];
        } catch (Exception $e) {
            Logger::logError('Критическое исключение в transferCompletedTasks: ' . $e->getMessage(), [
                'offset' => $currentOffset,
            ]);
            return ['created' => 0, 'offset' => $currentOffset, 'tasks' => []];
        }
    }
}
