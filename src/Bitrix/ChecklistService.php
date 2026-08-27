<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Support\Html;
use App\Support\Logger;
use CRest;
use Exception;

final class ChecklistService
{
    public static function createChecklist($taskId, $checklistItems, $userMapping): bool
    {
        try {
            if (empty($checklistItems)) {
                return false;
            }

            $idMapping = [];

            $sortedItems = [];
            foreach ($checklistItems as $item) {
                $level = 0;
                $parentId = $item['parent']['id'] ?? null;

                while ($parentId) {
                    $level++;
                    $parentFound = false;
                    foreach ($checklistItems as $parentItem) {
                        if ($parentItem['id'] == $parentId) {
                            $parentId = $parentItem['parent']['id'] ?? null;
                            $parentFound = true;
                            break;
                        }
                    }
                    if (!$parentFound) {
                        break;
                    }
                }

                $sortedItems[] = [
                    'item' => $item,
                    'level' => $level,
                ];
            }

            usort($sortedItems, function ($a, $b) {
                return $a['level'] <=> $b['level'];
            });

            foreach ($sortedItems as $sortedItem) {
                try {
                    $item = $sortedItem['item'];
                    $itemId = $item['id'];
                    $title = $item['name'] ?? 'Пункт чек-листа';
                    $isDone = !empty($item['isDone']);
                    $parentItemId = $item['parent']['id'] ?? null;

                    $bitrixParentId = 0;
                    if ($parentItemId && isset($idMapping[$parentItemId])) {
                        $bitrixParentId = $idMapping[$parentItemId];
                    }

                    $fields = [
                        'TITLE' => Html::toPlainText($title),
                        'IS_COMPLETE' => $isDone ? 'Y' : 'N',
                    ];

                    if ($bitrixParentId != 0) {
                        $fields['PARENT_ID'] = $bitrixParentId;
                    }

                    $result = CRest::call('task.checklistitem.add', [
                        'taskId' => $taskId,
                        'fields' => $fields,
                    ]);

                    if (isset($result['error'])) {
                        Logger::logError('Ошибка создания пункта чек-листа: ' . $result['error_description'], [
                            'task_id' => $taskId,
                            'item_title' => $title,
                            'parent_item_id' => $parentItemId,
                        ]);
                        continue;
                    }

                    $bitrixChecklistId = $result['result'];
                    $idMapping[$itemId] = $bitrixChecklistId;
                } catch (Exception $e) {
                    Logger::logError('Исключение при создании пункта чек-листа: ' . $e->getMessage(), [
                        'task_id' => $taskId,
                        'item_id' => $item['id'] ?? 'unknown',
                    ]);
                    continue;
                }
            }

            return true;
        } catch (Exception $e) {
            Logger::logError('Исключение при создании чек-листа: ' . $e->getMessage(), ['task_id' => $taskId]);
            return false;
        }
    }
}
