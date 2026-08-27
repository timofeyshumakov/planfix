<?php

declare(strict_types=1);

namespace App\Support;

use Exception;

final class Html
{
    /**
     * Преобразует HTML в простой текст
     */
    public static function toPlainText($html): string
    {
        try {
            if (empty($html)) {
                return '';
            }

            $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
            $html = str_replace(['</p>', '</div>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>'], "\n", $html);
            $html = str_replace('<li>', '• ', $html);

            $text = strip_tags($html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/[ \t]+/', ' ', $text);
            $text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text);

            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . "\n\n[текст обрезан...]";
            }

            return trim($text);
        } catch (Exception $e) {
            Logger::logError('Исключение при преобразовании HTML в текст: ' . $e->getMessage(), [
                'html_length' => strlen($html),
            ]);
            return '[Ошибка преобразования текста]';
        }
    }
}
