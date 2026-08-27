<?php

declare(strict_types=1);

namespace App\Planfix;

use App\Config;
use App\Support\Logger;
use Exception;

final class FileDownloader
{
    public static function download($fileId, $fileName, $planfixTaskId = null)
    {
        try {
            $baseUrl = Config::planfixBaseUrl();
            $apiToken = Config::planfixApiToken();

            $fileInfoEndpoint = $baseUrl . 'file/' . $fileId . '?fields=downloadUrl,size';
            $fileInfoResult = Client::request($fileInfoEndpoint, $apiToken);

            if (!$fileInfoResult['success']) {
                Logger::logError('Не удалось получить информацию о файле', ['file_id' => $fileId]);
                return null;
            }

            $downloadUrl = $fileInfoResult['data']['file']['downloadUrl'] ?? null;
            if (!$downloadUrl) {
                Logger::logError('URL для скачивания файла не найден', ['file_id' => $fileId]);
                return null;
            }

            $tempDir = Config::basePath() . '/temp_files';
            if (!file_exists($tempDir)) {
                if (!mkdir($tempDir, 0777, true)) {
                    Logger::logError('Не удалось создать временную директорию', ['directory' => $tempDir]);
                    return null;
                }
            }

            $safeFileName = preg_replace('/[^\w\.\-]/u', '_', $fileName);
            $tempFilePath = $tempDir . '/' . $safeFileName;

            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
            ]);

            $fileContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($fileContent === false || $httpCode !== 200) {
                Logger::logError('Ошибка скачивания файла', [
                    'file_id' => $fileId,
                    'url' => $downloadUrl,
                    'http_code' => $httpCode,
                    'curl_error' => $curlError,
                ]);
                return null;
            }

            $bytesWritten = file_put_contents($tempFilePath, $fileContent);
            if ($bytesWritten === false) {
                Logger::logError('Не удалось сохранить файл', ['path' => $tempFilePath]);
                return null;
            }

            return [
                'path' => $tempFilePath,
                'name' => $safeFileName,
                'original_name' => $fileName,
            ];
        } catch (Exception $e) {
            Logger::logError('Исключение при скачивании файла: ' . $e->getMessage(), [
                'file_id' => $fileId,
                'file_name' => $fileName,
            ]);
            return null;
        }
    }
}
