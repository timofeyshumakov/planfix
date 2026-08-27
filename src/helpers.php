<?php

declare(strict_types=1);

use App\Bitrix\ChecklistService;
use App\Bitrix\CommentService;
use App\Bitrix\DealService;
use App\Bitrix\FileUploader;
use App\Bitrix\TaskService;
use App\Bitrix\UserService;
use App\Config;
use App\Mapping\DealMapping;
use App\Mapping\MigrationOffset;
use App\Mapping\UserMapping;
use App\Migration\TransferService;
use App\Planfix\Client;
use App\Planfix\FileDownloader;
use App\Planfix\TaskApi;
use App\Support\Dates;
use App\Support\Html;
use App\Support\Logger;

if (!function_exists('checkConnection')) {
    function checkConnection()
    {
        return Logger::checkConnection();
    }
}

if (!function_exists('keepAlive')) {
    function keepAlive()
    {
        Logger::keepAlive();
    }
}

if (!function_exists('saveToJsonFile')) {
    function saveToJsonFile($data, $filename)
    {
        Logger::saveToJsonFile($data, $filename);
    }
}

if (!function_exists('logError')) {
    function logError($message, $context = [], $severity = 'ERROR')
    {
        Logger::logError($message, $context, $severity);
    }
}

if (!function_exists('safeExecute')) {
    function safeExecute($function, $args = [], $default = null)
    {
        return Logger::safeExecute($function, $args, $default);
    }
}

if (!function_exists('htmlToPlainText')) {
    function htmlToPlainText($html)
    {
        return Html::toPlainText($html);
    }
}

if (!function_exists('isValidEndDate')) {
    function isValidEndDate($startDateStr, $endDateStr)
    {
        return Dates::isValidEndDate($startDateStr, $endDateStr);
    }
}

if (!function_exists('loadUserMapping')) {
    function loadUserMapping()
    {
        return UserMapping::load();
    }
}

if (!function_exists('saveUserMapping')) {
    function saveUserMapping($mapping)
    {
        return UserMapping::save($mapping);
    }
}

if (!function_exists('addUserToMapping')) {
    function addUserToMapping($userName, $bitrixId)
    {
        return UserMapping::add($userName, $bitrixId);
    }
}

if (!function_exists('findBitrixUser')) {
    function findBitrixUser($userName, $userMapping)
    {
        return UserMapping::findBitrixUser($userName, $userMapping);
    }
}

if (!function_exists('getAllTaskUsersWithDetails')) {
    function getAllTaskUsersWithDetails($task)
    {
        return UserMapping::getAllTaskUsersWithDetails($task);
    }
}

if (!function_exists('loadDealMapping')) {
    function loadDealMapping()
    {
        return DealMapping::load();
    }
}

if (!function_exists('saveDealMapping')) {
    function saveDealMapping($mapping)
    {
        return DealMapping::save($mapping);
    }
}

if (!function_exists('findBitrixDeal')) {
    function findBitrixDeal($planfixProjectId)
    {
        return DealMapping::find($planfixProjectId);
    }
}

if (!function_exists('addDealToMapping')) {
    function addDealToMapping($planfixProjectId, $bitrixDealId)
    {
        return DealMapping::add($planfixProjectId, $bitrixDealId);
    }
}

if (!function_exists('getMigrationOffset')) {
    function getMigrationOffset($default = 0)
    {
        return MigrationOffset::get($default);
    }
}

if (!function_exists('saveMigrationOffset')) {
    function saveMigrationOffset($offset)
    {
        MigrationOffset::save($offset);
    }
}

if (!function_exists('makePlanfixRequest')) {
    function makePlanfixRequest($url, $apiToken, $data = null)
    {
        return Client::request($url, $apiToken, $data);
    }
}

if (!function_exists('getPlanfixTaskChecklist')) {
    function getPlanfixTaskChecklist($taskId, $apiToken = null, $baseUrl = null)
    {
        return TaskApi::getChecklist($taskId, $apiToken, $baseUrl);
    }
}

if (!function_exists('getPlanfixTaskCommentsApi')) {
    function getPlanfixTaskCommentsApi($taskId, $apiToken = null, $baseUrl = null)
    {
        return TaskApi::getCommentsApi($taskId, $apiToken, $baseUrl);
    }
}

if (!function_exists('getPlanfixTaskFiles')) {
    function getPlanfixTaskFiles($taskId, $apiToken = null, $baseUrl = null)
    {
        return TaskApi::getFiles($taskId, $apiToken, $baseUrl);
    }
}

if (!function_exists('getPlanfixTaskComments')) {
    function getPlanfixTaskComments($taskId)
    {
        return TaskApi::getCommentsFromJson($taskId);
    }
}

if (!function_exists('downloadPlanfixFile')) {
    function downloadPlanfixFile($fileId, $fileName, $planfixTaskId = null)
    {
        return FileDownloader::download($fileId, $fileName, $planfixTaskId);
    }
}

if (!function_exists('updateUserMappingFromBitrix')) {
    function updateUserMappingFromBitrix()
    {
        return UserService::updateUserMappingFromBitrix();
    }
}

if (!function_exists('createBitrixComment')) {
    function createBitrixComment($taskId, $commentData, $userMapping)
    {
        return CommentService::createComment($taskId, $commentData, $userMapping);
    }
}

if (!function_exists('createBitrixChecklist')) {
    function createBitrixChecklist($taskId, $checklistItems, $userMapping)
    {
        return ChecklistService::createChecklist($taskId, $checklistItems, $userMapping);
    }
}

if (!function_exists('createBitrixTask')) {
    function createBitrixTask($taskData)
    {
        return TaskService::createTask($taskData);
    }
}

if (!function_exists('createBitrixTasksBatch')) {
    function createBitrixTasksBatch($tasksData)
    {
        return TaskService::createTasksBatch($tasksData);
    }
}

if (!function_exists('findTasksByPlanfixIds')) {
    function findTasksByPlanfixIds(array $planfixIds)
    {
        return TaskService::findTasksByPlanfixIds($planfixIds);
    }
}

if (!function_exists('findTaskByPlanfixId')) {
    function findTaskByPlanfixId($planfixId)
    {
        return TaskService::findTaskByPlanfixId($planfixId);
    }
}

if (!function_exists('createBitrixDeal')) {
    function createBitrixDeal($projectId, $projectName)
    {
        return DealService::createDeal($projectId, $projectName);
    }
}

if (!function_exists('attachTaskFilesToBitrix')) {
    function attachTaskFilesToBitrix($taskId)
    {
        FileUploader::attachTaskFilesToBitrix($taskId);
    }
}

if (!function_exists('uploadFileToBitrixTask')) {
    function uploadFileToBitrixTask($taskId, $filePath, $fileName)
    {
        return FileUploader::uploadFileToBitrixTask($taskId, $filePath, $fileName);
    }
}

if (!function_exists('processTaskFiles')) {
    function processTaskFiles($bitrixTaskId, $planfixTaskId, $fileList = null)
    {
        return FileUploader::processTaskFiles($bitrixTaskId, $planfixTaskId, $fileList);
    }
}

if (!function_exists('transferCompletedTasks')) {
    function transferCompletedTasks($currentOffset = 0, $testMode = false)
    {
        return TransferService::transferCompletedTasks($currentOffset, $testMode);
    }
}
