<?php

declare(strict_types=1);

namespace App\Planfix;

use App\Support\Logger;
use Exception;

final class Client
{
    public static function request($url, $apiToken, $data = null): array
    {
        try {
            $ch = curl_init($url);

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiToken,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 30,
            ];

            if ($data !== null) {
                $options[CURLOPT_POST] = true;
                $jsonData = json_encode($data);
                if ($jsonData === false) {
                    throw new Exception('Ошибка кодирования JSON данных для запроса');
                }
                $options[CURLOPT_POSTFIELDS] = $jsonData;
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                Logger::logError('Ошибка cURL при запросе к Planfix', [
                    'url' => $url,
                    'error' => $curlError,
                    'http_code' => $httpCode,
                ]);
                return ['success' => false, 'error' => 'Ошибка cURL: ' . $curlError];
            }

            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::logError('Ошибка декодирования JSON ответа от Planfix', [
                    'url' => $url,
                    'json_error' => json_last_error_msg(),
                    'raw_response' => substr($response, 0, 500),
                ]);
                return ['success' => false, 'error' => 'Ошибка JSON: ' . json_last_error_msg()];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['success' => true, 'data' => $result];
            }

            Logger::logError('HTTP ошибка при запросе к Planfix', [
                'url' => $url,
                'http_code' => $httpCode,
                'response' => $result,
            ]);
            return ['success' => false, 'http_code' => $httpCode, 'data' => $result];
        } catch (Exception $e) {
            Logger::logError('Исключение при выполнении запроса к Planfix: ' . $e->getMessage(), ['url' => $url]);
            return ['success' => false, 'error' => 'Исключение: ' . $e->getMessage()];
        }
    }
}
