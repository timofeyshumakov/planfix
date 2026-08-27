<?php

declare(strict_types=1);

namespace App\Mapping;

use App\Config;
use App\Support\Logger;
use Exception;

final class MigrationOffset
{
    public static function get($default = 0)
    {
        try {
            $file = Config::basePath() . '/migration_offset.json';
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if ($content === false) {
                    Logger::logError('Не удалось прочитать файл смещения миграции', ['file' => $file]);
                    return $default;
                }

                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Logger::logError('Ошибка парсинга JSON смещения миграции: ' . json_last_error_msg());
                    return $default;
                }

                return $data['offset'] ?? $default;
            }
            return $default;
        } catch (Exception $e) {
            Logger::logError('Исключение при получении смещения миграции: ' . $e->getMessage());
            return $default;
        }
    }

    public static function save($offset): void
    {
        try {
            $data = ['offset' => $offset, 'updated' => date('Y-m-d H:i:s')];
            $jsonData = json_encode($data, JSON_PRETTY_PRINT);

            if ($jsonData === false) {
                Logger::logError('Ошибка кодирования JSON для смещения миграции');
                return;
            }

            file_put_contents(Config::basePath() . '/migration_offset.json', $jsonData);
        } catch (Exception $e) {
            Logger::logError('Исключение при сохранении смещения миграции: ' . $e->getMessage());
        }
    }
}
