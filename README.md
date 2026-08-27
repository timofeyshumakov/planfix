# Planfix → Bitrix24

PHP-приложение для переноса задач из Planfix в Битрикс24 (CRest): batch-создание, комментарии, чек-листы, вложения, маппинг пользователей и сделок.

## Возможности

- Получение задач через Planfix REST (`task/list`)
- Создание задач в Bitrix24 (`tasks.task.add` / `callBatch`)
- Перенос комментариев, чек-листов и файлов
- Сопоставление пользователей и проектов (сделки)
- Веб-консоль мониторинга переноса: [`console.php`](console.php)
- CLI/HTTP-запуск через [`handler.php`](handler.php)

<img style="max-width: 100%; height: auto;" alt="ezgif com-video-to-gif-converter" src="https://github.com/user-attachments/assets/0fb95377-bc95-4734-8ae9-7fae702700fb" />

## Структура

```
handler.php          # точка входа миграции
console.php          # веб-консоль переноса
src/
  Config.php         # переменные окружения
  Support/           # логирование, HTML, даты
  Mapping/           # user/deal/offset
  Planfix/           # REST-клиент и API задач
  Bitrix/            # создание задач, комментариев, сделок
  Migration/         # оркестрация переноса
tests/Unit/          # PHPUnit
```

## Установка

```bash
composer install
cp .env.example .env
# заполните PLANFIX_* и C_REST_* в .env
```

Откройте `install.php` в портале Bitrix24 для установки локального приложения.

## Запуск

- Миграция: `handler.php` (или `handler.php?test=true` для одной задачи)
- Консоль: `console.php` в браузере

## Переменные окружения

| Ключ | Назначение |
|------|------------|
| `PLANFIX_BASE_URL` | Базовый URL REST Planfix |
| `PLANFIX_API_TOKEN` | Bearer-токен Planfix |
| `C_REST_CLIENT_ID` | ID приложения Bitrix24 |
| `C_REST_CLIENT_SECRET` | Секрет приложения Bitrix24 |
| `DEFAULT_BITRIX_USER_ID` | Пользователь по умолчанию |
| `MIGRATION_*` | Параметры batch/итераций |

Файл `.env` не коммитится. Дампы (`tasks_raw/`, `comments*.json`) в репозиторий не входят.

## Тесты

```bash
composer test
# или: vendor/bin/phpunit
```
