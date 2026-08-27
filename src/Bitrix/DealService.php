<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Mapping\DealMapping;
use App\Support\Logger;
use CRest;
use Exception;

final class DealService
{
    public static function createDeal($projectId, $projectName)
    {
        try {
            $existingDealId = DealMapping::find($projectId);
            if ($existingDealId) {
                return $existingDealId;
            }

            $result = CRest::call(
                'crm.deal.add',
                [
                    'fields' => [
                        'TITLE' => $projectName,
                        'CATEGORY_ID' => 43,
                        'UF_CRM_1765261660' => $projectId,
                        'STAGE_ID' => 'NEW',
                        'TYPE_ID' => 'SALE',
                        'OPENED' => 'Y',
                    ],
                ]
            );

            if (isset($result['error'])) {
                Logger::logError('Ошибка создания сделки: ' . $result['error_description'], [
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                ]);
                return null;
            }

            $bitrixDealId = $result['result'];

            DealMapping::add($projectId, $bitrixDealId);

            return $bitrixDealId;
        } catch (Exception $e) {
            Logger::logError('Исключение при создании сделки: ' . $e->getMessage(), [
                'project_id' => $projectId,
                'project_name' => $projectName,
            ]);
            return null;
        }
    }
}
