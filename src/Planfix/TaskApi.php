<?php

declare(strict_types=1);

namespace App\Planfix;

use App\Config;
use App\Support\Logger;
use Exception;

final class TaskApi
{
    public static function getChecklist($taskId, $apiToken = null, $baseUrl = null): array
    {
        $apiToken = $apiToken ?? Config::planfixApiToken();
        $baseUrl = $baseUrl ?? Config::planfixBaseUrl();

        try {
            $checklistEndpoint = $baseUrl . 'task/' . $taskId . '/checklist/list';

            $data = [
                'offset' => 0,
                'pageSize' => 100,
                'fields' => 'name,parent,isDone',
            ];

            $checklistResult = Client::request($checklistEndpoint, $apiToken, $data);

            if (!$checklistResult['success']) {
                Logger::logError('Ошибка получения чек-листа', [
                    'task_id' => $taskId,
                    'http_code' => $checklistResult['http_code'] ?? 'N/A',
                ]);
                return [];
            }

            return $checklistResult['data']['items'] ?? [];
        } catch (Exception $e) {
            Logger::logError('Исключение при получении чек-листа: ' . $e->getMessage(), ['task_id' => $taskId]);
            return [];
        }
    }

    public static function getCommentsApi($taskId, $apiToken = null, $baseUrl = null): array
    {
        $apiToken = $apiToken ?? Config::planfixApiToken();
        $baseUrl = $baseUrl ?? Config::planfixBaseUrl();

        try {
            $commentsEndpoint = $baseUrl . 'task/' . $taskId . '/comments/list';

            $data = [
                'offset' => 0,
                'pageSize' => 100,
                'fields' => 'id,description,recipients',
                'typeList' => 'Comments',
                'resultOrder' => [
                    [
                        'field' => 'id',
                        'direction' => 'Asc',
                    ],
                    [
                        'field' => 'dateTime',
                        'direction' => 'Desc',
                    ],
                ],
            ];

            $commentsResult = Client::request($commentsEndpoint, $apiToken, $data);

            if (!$commentsResult['success']) {
                Logger::logError('Ошибка получения комментариев Planfix API', [
                    'task_id' => $taskId,
                    'http_code' => $commentsResult['http_code'] ?? 'N/A',
                ]);
                return [];
            }

            $rawComments = $commentsResult['data']['comments'] ?? [];
            array_shift($rawComments);

            $formattedComments = [];
            foreach ($rawComments as $comment) {
                $formattedComments[] = [
                    'id' => $comment['id'] ?? null,
                    'description' => $comment['description'] ?? '',
                    'owner' => [
                        'name' => trim(($comment['owner']['name'] ?? '') . ' ' . ($comment['owner']['lastName'] ?? '')),
                    ],
                    'datetime' => $comment['dateTime'] ?? date('Y-m-d H:i:s'),
                ];
            }

            echo 'Получено комментариев через API: ' . count($formattedComments) . "\n";
            return $formattedComments;
        } catch (Exception $e) {
            Logger::logError('Исключение при получении комментариев API: ' . $e->getMessage(), ['task_id' => $taskId]);
            return [];
        }
    }

    public static function getFiles($taskId, $apiToken = null, $baseUrl = null): array
    {
        $apiToken = $apiToken ?? Config::planfixApiToken();
        $baseUrl = $baseUrl ?? Config::planfixBaseUrl();

        try {
            $filesEndpoint = $baseUrl . 'task/' . $taskId . '/files';

            $filesResult = Client::request($filesEndpoint, $apiToken);

            if (!$filesResult['success']) {
                Logger::logError('Ошибка получения списка файлов', [
                    'task_id' => $taskId,
                    'http_code' => $filesResult['http_code'] ?? 'N/A',
                ]);
                return [];
            }

            return $filesResult['data']['files'] ?? [];
        } catch (Exception $e) {
            Logger::logError('Исключение при получении списка файлов: ' . $e->getMessage(), ['task_id' => $taskId]);
            return [];
        }
    }

    /**
     * Список задач Planfix (как в transferCompletedTasks).
     *
     * @return array{success: bool, data?: array, error?: string, http_code?: int}
     */
    public static function listTasks(array $requestData, $apiToken = null, $baseUrl = null): array
    {
        $apiToken = $apiToken ?? Config::planfixApiToken();
        $baseUrl = $baseUrl ?? Config::planfixBaseUrl();

        $tasksEndpoint = $baseUrl . 'task/list';
        return Client::request($tasksEndpoint, $apiToken, $requestData);
    }

    /**
     * Получает комментарии задачи из JSON файла (потоковая версия)
     */
    public static function getCommentsFromJson($taskId): array
    {
        try {
            $jsonFilePath = Config::basePath() . '/comments_enriched.json';

            if (!file_exists($jsonFilePath)) {
                echo "  Файл с комментариями не найден: {$jsonFilePath}\n";
                return [];
            }

            ini_set('memory_limit', '2048M');
            set_time_limit(300);

            $command = sprintf(
                'jq \'.[] | select(.task.number == %d)\' %s',
                escapeshellarg($taskId),
                escapeshellarg($jsonFilePath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                $filteredJson = '[' . implode(',', $output) . ']';
                $taskComments = json_decode($filteredJson, true);
            } else {
                $jsonContent = file_get_contents($jsonFilePath);
                $allComments = json_decode($jsonContent, true);

                $taskComments = array_filter($allComments, function ($comment) use ($taskId) {
                    return isset($comment['task']['number']) && $comment['task']['number'] == $taskId;
                });

                $taskComments = array_values($taskComments);
            }

            $formattedComments = [];
            foreach ($taskComments as $comment) {
                $formattedComments[] = [
                    'id' => $comment['id'] ?? null,
                    'description' => $comment['description'] ?? '',
                    'owner' => [
                        'name' => ($comment['owner']['name'] ?? '') . ' ' . ($comment['owner']['lastName'] ?? ''),
                    ],
                    'datetime' => $comment['dateTime'] ?? date('Y-m-d H:i:s'),
                    'files' => $comment['files'] ?? [],
                    'isSolution' => $comment['type'] === 'Solution'
                        || strpos($comment['description'], 'РЕШЕНИЕ') !== false,
                ];
            }

            return $formattedComments;
        } catch (Exception $e) {
            Logger::logError('Исключение при получении комментариев: ' . $e->getMessage(), ['task_id' => $taskId]);
            return [];
        }
    }
}
