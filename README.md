# Wialon Combine Protocol Parser

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue)](https://www.php.net/)

PHP библиотека для парсинга бинарного протокола Wialon Combine, используемого в GPS/ГЛОНАСС трекерах.

## Описание

Wialon Combine Protocol Parser - это PHP библиотека для декодирования бинарных пакетов, передаваемых GPS/ГЛОНАСС трекерами по протоколу **Wialon Combine 1.0.3**. Библиотека поддерживает все типы пакетов: Login, Data, Keep-Alive и все подзаписи: Custom Parameters, Position Data, Picture, LBS Parameters.

## Возможности

- ✅ Парсинг всех типов пакетов (Login, Data, Keep-Alive)
- ✅ Поддержка всех подзаписей (Custom Parameters, Position Data, Picture, LBS)
- ✅ Валидация CRC16 контрольной суммы
- ✅ Big-Endian порядок байт
- ✅ Расширяемые поля (1-2 и 2-4 байта)
- ✅ Автоматическая генерация ответов сервера
- ✅ Преобразование в отдельные записи
- ✅ PSR-4 автозагрузка
- ✅ Полное покрытие тестами

## Установка

### Через Composer

```bash
composer require wialon/combine-protocol-parser
```

### Ручная установка

```bash
git clone https://github.com/your-username/wialon-combine-protocol-parser.git
cd wialon-combine-protocol-parser
composer install
```

## Быстрый старт

### Базовое использование

```php
<?php

require_once 'vendor/autoload.php';

use Wialon\Combine\WialonParser;

// Создание парсера
$parser = new WialonParser();

// Парсинг hex-данных
$hexData = '24 24 01 00 02 00 5a 68 9f 10 f9 02 01 02 65 23 68 02 a7 64 0c 00 37 00 2c 03 fc 10 00 3f 00 80 0e 19 00 00 09 02 00 36 05 8e 0b 00 01 6d 00 0d 6e 00 0a 71 08 47 10 e2 7b 72 00 01 84 d9 00 01 84 e2 08 42 48 00 00 84 e4 08 41 49 70 a4 84 e5 08 3f d3 33 33 87 d5 00 14 8a f3 00 03 8b 43 00 03 b8 cf';
$parser->setData($hexData);
$result = $parser->parse();

// Вывод результата
echo "Тип пакета: " . $result['type'] . "\n";
echo "Порядковый номер: " . $result['sequence'] . "\n";
echo "Записей: " . count($result['records']) . "\n";
echo "CRC: " . $result['crc']['calculated'] . "\n";

```

### Парсинг из файла

```php
<?php

use Wialon\Combine\WialonParser;

// Парсинг из файла с hex-данными
$result = WialonParser::parseFromFile('example.dat');

// Обработка записей
foreach ($result['records'] as $record) {
    if ($record['type'] === 'Position Data') {
        echo "Координаты: {$record['latitude']}, {$record['longitude']}\n";
        echo "Скорость: {$record['speed']} км/ч\n";
    } elseif ($record['type'] === 'Custom Parameters') {
        echo "Датчик {$record['sensor']}: {$record['value']}\n";
    }
}
```

### Обработка множественных пакетов

```php
<?php

use Wialon\Combine\WialonParser;

$lines = file('packets.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$parser = new WialonParser();

foreach ($lines as $lineNumber => $hexData) {
    try {
        $parser->setData($hexData);
        $result = $parser->parse();
        
        echo "Пакет #" . ($lineNumber + 1) . ":\n";
        echo "Ответ сервера: " . json_encode($result['server_response']) . "\n";
        echo "Записей: " . count($result['records']) . "\n\n";
        
    } catch (Exception $e) {
        echo "Ошибка парсинга пакета #" . ($lineNumber + 1) . ": " . $e->getMessage() . "\n";
    }
}
```

## Структура данных

### Результат парсинга

```php
[
    'head' => '0x2424',           // Заголовок пакета
    'type' => 1,                  // Тип пакета (0=Login, 1=Data, 2=Keep-Alive)
    'sequence' => 2,              // Порядковый номер
    'length' => 90,               // Длина данных
    'crc' => [                    // CRC16 валидация
        'received' => 12345,
        'calculated' => 12345,
        'valid' => true
    ],
    'data' => [...],              // Структурированные данные пакета
    'records' => [...],           // Отдельные записи
    'server_response' => [...]    // Ответ сервера
]
```

### Типы записей

#### Position Data
```php
[
    'time' => 1691389688,
    'type' => 'Position Data',
    'latitude' => 42.876688,
    'longitude' => 47.622288,
    'speed' => 55,
    'course' => 44,
    'height' => 1020,
    'satellites' => 16,
    'hdop' => 0.63
]
```

#### Custom Parameters
```php
[
    'time' => 1691389688,
    'type' => 'Custom Parameters',
    'sensor' => 25,
    'sensor_type' => 0,
    'value' => 3
]
```

#### Login
```php
[
    'type' => 'Login',
    'protocol_version' => 1,
    'flags' => 48,
    'id' => 860103063062252,
    'password' => null
]
```

## API Reference

### WialonParser

#### Методы

- `__construct(string $hexData = '')` - Конструктор
- `setData(string $hexData)` - Установка hex-данных для парсинга
- `parse()` - Парсинг пакета
- `generateServerResponse(int $code, int $seq)` - Генерация ответа сервера
- `parseFromFile(string $filename)` - Статический метод парсинга из файла

#### Коды ответов сервера

- `0` - Пакет успешно зарегистрирован
- `1` - Ошибка авторизации
- `2` - Неверный пароль
- `3` - Пакет не зарегистрирован
- `4` - Ошибка CRC
- `255` - Команда на устройство

## Тестирование

```bash
# Запуск тестов
composer test

# Запуск с покрытием
composer test-coverage
```

## Требования

- PHP >= 7.4
- Composer (для установки)

## Лицензия

MIT License.

## Поддержка

Если у вас есть вопросы или предложения, создайте [issue](https://github.com/piplexa/wialon-combine-protocol-parser/issues).

## Changelog

### v1.0.1
- В ответе парсинга добавлен hex-представление ответа
- Исправлены мелкие баги в валидации CRC

### v1.0.0
- Первоначальный релиз
- Поддержка всех типов пакетов Wialon Combine
- Валидация CRC16
- Автоматическая генерация ответов сервера
- Преобразование в отдельные записи
