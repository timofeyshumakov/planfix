<?php

declare(strict_types=1);

namespace App\Support;

use DateTime;
use Exception;

final class Dates
{
    /**
     * Проверяет, что END_DATE_PLAN > START_DATE_PLAN
     */
    public static function isValidEndDate($startDateStr, $endDateStr): bool
    {
        if (empty($startDateStr) || empty($endDateStr)) {
            return true;
        }

        try {
            $startDate = new DateTime($startDateStr);
            $endDate = new DateTime($endDateStr);
            return $endDate > $startDate;
        } catch (Exception $e) {
            Logger::logError("Invalid date format: start={$startDateStr}, end={$endDateStr}", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
