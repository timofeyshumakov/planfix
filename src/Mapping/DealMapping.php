<?php

declare(strict_types=1);

namespace App\Mapping;

use App\Config;
use App\Runtime;
use App\Support\Logger;
use Exception;

final class DealMapping
{
    public static function load(): array
    {
        try {
            $mappingFile = Config::basePath() . '/deal_mapping.json';

            if (!file_exists($mappingFile)) {
                file_put_contents($mappingFile, json_encode([], JSON_PRETTY_PRINT));
                echo "Создан пустой файл сопоставления сделок\n";
                return [];
            }

            $content = file_get_contents($mappingFile);
            if ($content === false) {
                Logger::logError('Не удалось прочитать файл сопоставления сделок', ['file' => $mappingFile]);
                return [];
            }

            $mapping = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::logError('Ошибка загрузки сопоставления сделок: ' . json_last_error_msg(), [
                    'file' => $mappingFile,
                    'json_error' => json_last_error(),
                ]);
                return [];
            }

            return $mapping ?: [];
        } catch (Exception $e) {
            Logger::logError('Исключение при загрузке deal mapping: ' . $e->getMessage());
            return [];
        }
    }

    public static function save($mapping): bool
    {
        try {
            $mappingFile = Config::basePath() . '/deal_mapping.json';
            $jsonData = json_encode($mapping, JSON_PRETTY_PRINT);

            if ($jsonData === false) {
                Logger::logError('Ошибка кодирования JSON для deal mapping');
                return false;
            }

            $result = file_put_contents($mappingFile, $jsonData);

            if ($result === false) {
                Logger::logError('Ошибка сохранения сопоставления сделок', ['file' => $mappingFile]);
                return false;
            }

            return true;
        } catch (Exception $e) {
            Logger::logError('Исключение при сохранении deal mapping: ' . $e->getMessage());
            return false;
        }
    }

    public static function find($planfixProjectId)
    {
        try {
            if (empty($planfixProjectId)) {
                return null;
            }

            if (isset(Runtime::$dealMapping[$planfixProjectId])) {
                return Runtime::$dealMapping[$planfixProjectId];
            }

            return null;
        } catch (Exception $e) {
            Logger::logError('Исключение при поиске сделки: ' . $e->getMessage(), [
                'project_id' => $planfixProjectId,
            ]);
            return null;
        }
    }

    public static function add($planfixProjectId, $bitrixDealId): bool
    {
        try {
            if (empty($planfixProjectId) || empty($bitrixDealId)) {
                return false;
            }

            Runtime::$dealMapping[$planfixProjectId] = $bitrixDealId;

            return self::save(Runtime::$dealMapping);
        } catch (Exception $e) {
            Logger::logError('Исключение при добавлении сделки в маппинг: ' . $e->getMessage(), [
                'project_id' => $planfixProjectId,
                'deal_id' => $bitrixDealId,
            ]);
            return false;
        }
    }
}
