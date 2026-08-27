# План батчевой проверки дублей задач (50 ID за раз)

## ⬜ Шаг 1: Создать TODO_batch_check.md (выполнено)

## ⬜ Шаг 2: Создать batchFindTasksByPlanfixIds($planfixIds)
- Получать массив ID (макс 50)
- Фильтр: ["UF_AUTO_127801401239" => array of IDs]
- Возврат map: planfixId => bitrixId

## ⬜ Шаг 3: В transferCompletedTasks()
- Собирать batch по 50 ID
- Проверять батчем перед добавлением в tasksToCreate

## ⬜ Шаг 4: Обновить createBitrixTasksBatch()
- Аналогично батчевая проверка

## ⬜ Шаг 5: Тестирование и завершение
