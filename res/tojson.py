import json

# Словарь для перевода русских названий в английские
translation_dict = {
    "Номер": "id",
    "Срочность": "priority",
    "Название": "title",
    "Дата планируемого завершения": "planned_completion_date",
    "Постановщик": "creator",
    "Исполнители": "assignees",
    "Название проекта": "project_name",
    "Участники": "participants",
    "Тип задачи": "task_type"
}

def translate_json_keys(data, translation_dict):
    """
    Преобразует ключи JSON из русского в английский согласно словарю перевода
    
    Args:
        data: данные JSON (словарь или список)
        translation_dict: словарь перевода ключей
    
    Returns:
        данные с переведенными ключами
    """
    if isinstance(data, dict):
        # Обрабатываем словарь
        translated_dict = {}
        for key, value in data.items():
            # Переводим ключ
            new_key = translation_dict.get(key, key)  # Если ключ не найден, оставляем как есть
            # Рекурсивно обрабатываем значение
            translated_dict[new_key] = translate_json_keys(value, translation_dict)
        return translated_dict
    elif isinstance(data, list):
        # Обрабатываем список
        return [translate_json_keys(item, translation_dict) for item in data]
    else:
        # Возвращаем неизмененные значения (строки, числа, None и т.д.)
        return data

def process_json_file(input_file, output_file, translation_dict):
    """
    Читает JSON файл, переводит ключи и сохраняет результат
    
    Args:
        input_file: путь к входному JSON файлу
        output_file: путь для сохранения результата
        translation_dict: словарь перевода ключей
    """
    try:
        # Чтение JSON файла
        with open(input_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        # Перевод ключей
        translated_data = translate_json_keys(data, translation_dict)
        
        # Сохранение результата
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(translated_data, f, ensure_ascii=False, indent=2)
        
        print(f"Файл успешно обработан. Результат сохранен в {output_file}")
        
        # Вывод примера для проверки
        print("\nПример преобразованной записи:")
        if isinstance(translated_data, list) and len(translated_data) > 0:
            print(json.dumps(translated_data[0], ensure_ascii=False, indent=2))
        elif isinstance(translated_data, dict):
            print(json.dumps(translated_data, ensure_ascii=False, indent=2))
            
    except FileNotFoundError:
        print(f"Ошибка: Файл {input_file} не найден")
    except json.JSONDecodeError:
        print(f"Ошибка: Файл {input_file} содержит некорректный JSON")
    except Exception as e:
        print(f"Произошла ошибка: {str(e)}")

# Альтернативная функция для обработки одиночного объекта
def translate_single_object(json_obj, translation_dict):
    """
    Преобразует ключи одного JSON объекта
    
    Args:
        json_obj: JSON объект (словарь)
        translation_dict: словарь перевода
    
    Returns:
        преобразованный объект
    """
    return translate_json_keys(json_obj, translation_dict)

# Пример использования
if __name__ == "__main__":
    process_json_file('tasks.json', 'tasks.json', translation_dict)