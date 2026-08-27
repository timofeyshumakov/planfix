<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Mapping\UserMapping;
use App\Support\Logger;
use CRest;
use Exception;

final class UserService
{
    public static function updateUserMappingFromBitrix(): array
    {
        try {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "ОБНОВЛЕНИЕ СОПОСТАВЛЕНИЯ ПОЛЬЗОВАТЕЛЕЙ:\n";
            echo str_repeat('=', 60) . "\n";

            $allUsers = [];
            $start = 0;
            $batchSize = 50;
            $maxIterations = 10;

            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                echo "\nИтерация {$iteration} из {$maxIterations}:\n";

                $result = CRest::call('user.get', [
                    'filter' => ['ACTIVE' => true],
                    'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'WORK_POSITION'],
                    'start' => $start,
                ]);

                if (isset($result['error'])) {
                    Logger::logError('Ошибка получения пользователей из Bitrix: ' . $result['error_description']);
                    if ($iteration === 1) {
                        return UserMapping::load();
                    }
                    break;
                }

                if (empty($result['result'])) {
                    echo "Нет данных для обработки. Завершение.\n";
                    break;
                }

                $usersCount = count($result['result']);
                echo "Получено {$usersCount} пользователей\n";

                $allUsers = array_merge($allUsers, $result['result']);

                if ($usersCount < $batchSize) {
                    echo "Достигнут конец списка пользователей.\n";
                    break;
                }

                $start += $batchSize;
                sleep(0.3);
            }

            $newMapping = [];
            $updatedCount = 0;
            $totalUsers = count($allUsers);

            echo "\nОбработка {$totalUsers} пользователей:\n";

            foreach ($allUsers as $user) {
                try {
                    $fullName = trim(($user['NAME'] ?? '') . ' '
                        . ($user['LAST_NAME'] ?? ''));

                    if (!empty($fullName)) {
                        $newMapping[$fullName] = $user['ID'];
                    }

                    $updatedCount++;
                } catch (Exception $e) {
                    Logger::logError('Ошибка обработки пользователя: ' . $e->getMessage(), ['user_data' => $user]);
                    continue;
                }
            }

            if (UserMapping::save($newMapping)) {
                echo "\n" . str_repeat('-', 60) . "\n";
                echo "ИТОГИ ОБНОВЛЕНИЯ:\n";
                echo str_repeat('-', 60) . "\n";
                echo "Всего получено пользователей: {$totalUsers}\n";
                echo 'Записей в сопоставлении: ' . count($newMapping) . "\n";
                echo str_repeat('=', 60) . "\n";
            } else {
                Logger::logError('Ошибка сохранения сопоставления пользователей');
            }

            return $newMapping;
        } catch (Exception $e) {
            Logger::logError('Исключение при обновлении маппинга пользователей: ' . $e->getMessage());
            return UserMapping::load();
        }
    }
}
