<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Config;
use App\Planfix\FileDownloader;
use App\Support\Logger;
use CRest;
use Exception;

final class FileUploader
{
    public static function attachTaskFilesToBitrix($taskId): void
    {
    }

    public static function uploadFileToBitrixTask($taskId, $filePath, $fileName)
    {
        try {
            if (!file_exists($filePath)) {
                Logger::logError('Файл не найден для загрузки', [
                    'task_id' => $taskId,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                ]);
                return false;
            }

            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                Logger::logError('Не удалось прочитать файл', [
                    'task_id' => $taskId,
                    'file_path' => $filePath,
                ]);
                return false;
            }

            $base64Content = base64_encode($fileContent);

            $result = CRest::call('task.item.addfile', [
                'TASK_ID' => $taskId,
                'FILE' => [
                    'NAME' => $fileName,
                    'CONTENT' => $base64Content,
                ],
            ]);

            if (isset($result['error'])) {
                Logger::logError('Ошибка загрузки файла через task.item.addfile: ' . $result['error_description'], [
                    'task_id' => $taskId,
                    'file_name' => $fileName,
                    'error_code' => $result['error'],
                ]);
            }

            $fileId = $result['result'];
            echo "  Файл прикреплен к задаче: {$fileName} (ID: {$fileId})\n";

            return $fileId;
        } catch (Exception $e) {
            Logger::logError('Исключение при загрузке файла в Битрикс: ' . $e->getMessage(), [
                'task_id' => $taskId,
                'file_name' => $fileName,
                'file_path' => $filePath,
            ]);
        }
    }

    public static function processTaskFiles($bitrixTaskId, $planfixTaskId, $fileList = null): bool
    {
        try {
            echo "\nОбработка файлов для задачи Planfix ID: {$planfixTaskId}, Bitrix ID: {$bitrixTaskId}\n";

            if (empty($fileList)) {
                echo "  Нет файлов для обработки\n";
                return true;
            }

            echo '  Найдено файлов: ' . count($fileList) . "\n";

            $successCount = 0;
            $failCount = 0;

            foreach ($fileList as $file) {
                try {
                    $fileId = $file['id'] ?? null;
                    $fileName = $file['name'] ?? 'file_' . $fileId;

                    if (!$fileId) {
                        Logger::logError('Пропуск файла без ID', ['file' => $file]);
                        $failCount++;
                        continue;
                    }

                    echo "  Обработка файла: {$fileName}\n";

                    $downloadedFile = FileDownloader::download($fileId, $fileName, $planfixTaskId);

                    if (!$downloadedFile) {
                        $failCount++;
                        continue;
                    }

                    $uploadResult = self::uploadFileToBitrixTask($bitrixTaskId, $downloadedFile['path'], $downloadedFile['name']);

                    if ($uploadResult) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }

                    if (file_exists($downloadedFile['path'])) {
                        unlink($downloadedFile['path']);
                    }

                    usleep(500000);
                } catch (Exception $e) {
                    Logger::logError('Исключение при обработке файла: ' . $e->getMessage(), [
                        'file_id' => $file['id'] ?? 'unknown',
                        'file_name' => $file['name'] ?? 'unknown',
                    ]);
                    $failCount++;
                    continue;
                }
            }

            $taskTempDir = Config::basePath() . '/temp_files/task_' . $planfixTaskId;
            if (file_exists($taskTempDir)) {
                $files = glob($taskTempDir . '/*');
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
                rmdir($taskTempDir);
            }

            echo "  Результат обработки файлов: успешно {$successCount}, ошибок {$failCount}\n";

            return $successCount > 0;
        } catch (Exception $e) {
            Logger::logError('Исключение при обработке файлов задачи: ' . $e->getMessage(), [
                'bitrix_task_id' => $bitrixTaskId,
                'planfix_task_id' => $planfixTaskId,
            ]);
            return false;
        }
    }
}
