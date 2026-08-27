# План исправления логики дат для незавершенных задач

## ⬜ Шаг 1: Создать TODO_deadline.md (выполнено)

## ✅ Шаг 2: Определить статус завершенности ✓
- Planfix status 3 = завершенная → CLOSED_DATE
- Planfix status 1,2 = не завершенная → DEADLINE ✓

## ✅ Шаг 3: Изменить в transferCompletedTasks() ✓
- Добавлено taskData['isCompleted'] на основе status.id ✓

## ✅ Шаг 4: Изменить в createBitrixTask() и createBitrixTasksBatch() ✓
- Условное заполнение DEADLINE/CLOSED_DATE ✓

## ⬜ Шаг 5: Тестирование и завершение
