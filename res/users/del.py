import json

# Чтение JSON файла
with open('tasks.json', 'r', encoding='utf-8') as f:
    data = json.load(f)

# Проверяем, что это список и содержит достаточно элементов
if isinstance(data, list) and len(data) > 6000:
    # Удаляем первые 51000 элементов
    data = data[6000:]
else:
    print(f"Массив содержит только {len(data)} элементов")

# Запись обратно в файл
with open('tasks.json', 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

print(f"Осталось {len(data)} элементов")