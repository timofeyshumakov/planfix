<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Config;
use App\Mapping\UserMapping;
use App\Support\Html;
use App\Support\Logger;
use CRest;
use Exception;

final class CommentService
{
    public static function createComment($taskId, $commentData, $userMapping)
    {
        try {
            $authorName = $commentData['owner']['name'] ?? 'Неизвестный';
            $originalAuthorId = UserMapping::findBitrixUser($authorName, $userMapping);
            $defaultUserId = Config::defaultBitrixUserId();

            echo "Создание комментария для задачи {$taskId}\n";
            echo "Автор из Planfix: {$authorName}\n";
            echo 'Найденный ID в Битрикс: ' . ($originalAuthorId ?? 'не найден') . "\n";

            $commentText = Html::toPlainText($commentData['description'] ?? '');
            $commentDate = isset($commentData['datetime'])
                ? str_replace('T', ' ', substr($commentData['datetime']['datetime'], 0, -1))
                : date('Y-m-d H:i:s');

            $postMessage = '';

            if (!empty($authorName)) {
                $postMessage .= "[b]Автор:[/b] {$authorName}\n";
            }

            if (!empty($commentDate)) {
                $postMessage .= "[b]Дата:[/b] {$commentDate}\n";
            }

            $postMessage .= "\n" . $commentText;

            $fields = [
                'POST_MESSAGE' => $postMessage,
                'AUTHOR_ID' => $originalAuthorId ?? $defaultUserId,
                'POST_DATE' => $commentData['datetime'],
            ];

            $result = CRest::call('task.commentitem.add', [
                'TASK_ID' => $taskId,
                'FIELDS' => $fields,
            ]);

            if (isset($result['error'])) {
                $errorDescription = $result['error_description'] ?? '';
                $errorCode = $result['error'] ?? '';

                echo "Ошибка при создании комментария: {$errorDescription}\n";

                $permissionErrors = [
                    'Недостаточно прав для добавления комментария',
                    'access denied',
                    'permission denied',
                    'insufficient permissions',
                    'доступ запрещен',
                    'ACCESS_DENIED',
                ];

                $isPermissionError = false;
                foreach ($permissionErrors as $errorPattern) {
                    if (stripos($errorDescription, $errorPattern) !== false
                        || stripos($errorCode, $errorPattern) !== false) {
                        $isPermissionError = true;
                        break;
                    }
                }

                if ($isPermissionError) {
                    echo "Обнаружена ошибка прав доступа. Пробуем с AUTHOR_ID = {$defaultUserId}...\n";

                    $retryFields = [
                        'POST_MESSAGE' => $postMessage,
                        'AUTHOR_ID' => $defaultUserId,
                        'POST_DATE' => $commentData['datetime'],
                    ];

                    $retryResult = CRest::call('task.commentitem.add', [
                        'TASK_ID' => $taskId,
                        'FIELDS' => $retryFields,
                    ]);

                    if (isset($retryResult['error'])) {
                        Logger::logError(
                            "Критическая ошибка создания комментария с AUTHOR_ID={$defaultUserId}: "
                            . $retryResult['error_description'],
                            [
                                'task_id' => $taskId,
                                'comment_data' => $commentData,
                                'original_author' => $authorName,
                                'original_error' => $errorDescription,
                                'retry_error' => $retryResult['error_description'],
                            ]
                        );
                        echo 'Критическая ошибка: ' . $retryResult['error_description'] . "\n";
                        return false;
                    }

                    return $retryResult['result'];
                }

                Logger::logError('Ошибка создания комментария: ' . $errorDescription, [
                    'task_id' => $taskId,
                    'comment_data' => $commentData,
                    'author_name' => $authorName,
                    'author_id_attempted' => $originalAuthorId ?? $defaultUserId,
                    'error_code' => $errorCode,
                ]);
                return false;
            }

            $commentId = $result['result'];
            echo "Комментарий успешно создан (ID: {$commentId})\n";

            return $commentId;
        } catch (Exception $e) {
            Logger::logError('Исключение при создании комментария: ' . $e->getMessage(), [
                'task_id' => $taskId,
                'comment_data' => $commentData,
                'author_name' => $authorName ?? 'unknown',
            ]);
            return false;
        }
    }
}
