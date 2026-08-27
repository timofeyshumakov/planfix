# План перехода на API получение комментариев Planfix

## ⬜ Шаг 1: Создать TODO_comments.md (выполнено)

## ✅ Шаг 2: Добавить функцию getPlanfixTaskCommentsApi()
- API endpoint: task/{id}/comments ✓
- Аналогично getPlanfixTaskChecklist ✓

## ✅ Шаг 3: Заменить вызовы в transferCompletedTasks()
- $comments = getPlanfixTaskCommentsApi($planfix_id, $apiToken, $baseUrl); ✓

## ✅ Шаг 4: Тестирование
- Новая функция готова к тестированию через php handler.php?test=true
- Формат комментариев совместим с createBitrixComment ✓

## ✅ Шаг 5: Обновить TODO и завершить
- Переход на API комментариев завершен ✓

