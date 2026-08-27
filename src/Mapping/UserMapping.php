<?php

declare(strict_types=1);

namespace App\Mapping;

use App\Config;
use App\Support\Logger;
use Exception;

final class UserMapping
{
    public static function load(): array
    {
        try {
            $mappingFile = Config::basePath() . '/user_mapping.json';

            if (!file_exists($mappingFile)) {
                file_put_contents($mappingFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "Создан пустой файл сопоставления пользователей\n";
                return [];
            }

            $content = file_get_contents($mappingFile);
            if ($content === false) {
                Logger::logError('Не удалось прочитать файл сопоставления пользователей', ['file' => $mappingFile]);
                return [];
            }

            $mapping = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::logError('Ошибка загрузки сопоставления пользователей: ' . json_last_error_msg(), [
                    'file' => $mappingFile,
                    'json_error' => json_last_error(),
                ]);
                return [];
            }

            return $mapping ?: [];
        } catch (Exception $e) {
            Logger::logError('Исключение при загрузке user mapping: ' . $e->getMessage());
            return [];
        }
    }

    public static function save($mapping): bool
    {
        try {
            $mappingFile = Config::basePath() . '/user_mapping.json';
            $jsonData = json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($jsonData === false) {
                Logger::logError('Ошибка кодирования JSON для user mapping');
                return false;
            }

            $result = file_put_contents($mappingFile, $jsonData);

            if ($result === false) {
                Logger::logError('Ошибка сохранения сопоставления пользователей', ['file' => $mappingFile]);
                return false;
            }

            return true;
        } catch (Exception $e) {
            Logger::logError('Исключение при сохранении user mapping: ' . $e->getMessage());
            return false;
        }
    }

    public static function add($userName, $bitrixId): bool
    {
        try {
            if (empty($userName) || empty($bitrixId)) {
                return false;
            }

            $userName = trim($userName);
            \App\Runtime::$userMapping[$userName] = $bitrixId;

            return self::save(\App\Runtime::$userMapping);
        } catch (Exception $e) {
            Logger::logError('Исключение при добавлении пользователя в маппинг: ' . $e->getMessage(), [
                'user_name' => $userName,
                'bitrix_id' => $bitrixId,
            ]);
            return false;
        }
    }

    public static function findBitrixUser($userName, $userMapping)
    {
        try {
            if (empty($userName)) {
                return null;
            }

            $userName = trim($userName);

            if (isset($userMapping[$userName])) {
                return $userMapping[$userName];
            }

            $inputParts = array_filter(array_map('trim', explode(' ', $userName)));
            if (count($inputParts) === 1) {
                return null;
            }

            foreach ($userMapping as $mappedName => $bitrixId) {
                $mappedParts = array_filter(array_map('trim', explode(' ', $mappedName)));

                $allPartsMatch = true;
                foreach ($inputParts as $inputPart) {
                    $partFound = false;
                    foreach ($mappedParts as $mappedPart) {
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

            if (count($inputParts) >= 2) {
                foreach ($userMapping as $mappedName => $bitrixId) {
                    $mappedParts = array_filter(array_map('trim', explode(' ', $mappedName)));

                    if (count($mappedParts) >= 2) {
                        $foundFirstName = false;
                        $foundLastName = false;

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
        } catch (Exception $e) {
            Logger::logError('Исключение при поиске пользователя Bitrix: ' . $e->getMessage(), ['user_name' => $userName]);
            return null;
        }
    }

    /**
     * Определяет всех пользователей задачи с сохранением типов
     */
    public static function getAllTaskUsersWithDetails($task): array
    {
        try {
            $allUsers = [];

            if (!empty($task['assigner']) && !empty($task['assigner']['name'])) {
                $allUsers[] = [
                    'name' => $task['assigner']['name'],
                    'type' => 'creator',
                ];
            }

            if (!empty($task['assignees']['users']) && is_array($task['assignees']['users'])) {
                foreach ($task['assignees']['users'] as $assignee) {
                    if (!empty($assignee['name'])) {
                        $allUsers[] = [
                            'name' => $assignee['name'],
                            'type' => 'responsible',
                        ];
                    }
                }
            }

            if (!empty($task['assignees']['groups']) && is_array($task['assignees']['groups'])) {
                foreach ($task['assignees']['groups'] as $group) {
                    if (!empty($group['name'])) {
                        $allUsers[] = [
                            'name' => $group['name'],
                            'type' => 'responsible_group',
                        ];
                    }
                }
            }

            if (!empty($task['members']['users']) && is_array($task['members']['users'])) {
                foreach ($task['members']['users'] as $member) {
                    if (!empty($member['name'])) {
                        $allUsers[] = [
                            'name' => $member['name'],
                            'type' => 'accomplice',
                        ];
                    }
                }
            }

            if (!empty($task['members']['groups']) && is_array($task['members']['groups'])) {
                foreach ($task['members']['groups'] as $group) {
                    if (!empty($group['name'])) {
                        $allUsers[] = [
                            'name' => $group['name'],
                            'type' => 'accomplice_group',
                        ];
                    }
                }
            }

            if (!empty($task['auditors']['users']) && is_array($task['auditors']['users'])) {
                foreach ($task['auditors']['users'] as $auditor) {
                    if (!empty($auditor['name'])) {
                        $allUsers[] = [
                            'name' => $auditor['name'],
                            'type' => 'auditor',
                        ];
                    }
                }
            }

            if (!empty($task['auditors']['groups']) && is_array($task['auditors']['groups'])) {
                foreach ($task['auditors']['groups'] as $group) {
                    if (!empty($group['name'])) {
                        $allUsers[] = [
                            'name' => $group['name'],
                            'type' => 'auditor_group',
                        ];
                    }
                }
            }

            return $allUsers;
        } catch (Exception $e) {
            Logger::logError('Исключение при определении пользователей задачи: ' . $e->getMessage(), [
                'task_id' => $task['id'] ?? 'unknown',
            ]);
            return [];
        }
    }
}
