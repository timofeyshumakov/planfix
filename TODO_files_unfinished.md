# План добавления переноса файлов только для незавершенных задач

## Status: Planning

**Goal**: Изменить логику в handler.php - перенос файлов только для незавершённых задач Planfix (status.id != 3 && !isCompleted).

### Information Gathered:
- Файл: handler.php
- Функция: transferCompletedTasks() 
- Место: цикл foreach ($createdTasks), блок обработки файлов после checklist
- Текущая логика: processTaskFiles() вызывается для ВСЕХ созданных задач
- Статус незавершённой: $planfixStatusId != 3 && !$task['isCompleted']

### Plan:
1. ✅ Создать TODO_files_unfinished.md
2. Добавить условие if (!$isCompleted) вокруг блока getPlanfixTaskFiles() → processTaskFiles()
3. Добавить лог \"Файлы пропущены (завершённая задача)\" для finished tasks
4. Тестирование: php handler.php?test=true

### Dependent Files:
- handler.php (main)

### Followup steps:
- Запустить php handler.php?test=true 
- Проверить логи: для test task 60 файлы перенесены/пропущены правильно
- Проверить error_log.json

**Ready to edit handler.php?**

