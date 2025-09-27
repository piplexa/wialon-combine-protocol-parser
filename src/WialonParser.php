<?php

namespace Wialon\Combine;

/**
 * Wialon Combine Protocol Parser v1.0
 * 
 * Парсер бинарного протокола Wialon Combine для устройств GPS/ГЛОНАСС трекеров.
 * Поддерживает пакеты: Login, Data, Keep-Alive.
 * 
 * Алгоритм работы:
 * 1. Чтение и конвертация hex-данных в бинарный формат
 * 2. Парсинг заголовка пакета (Head, Type, Seq, Len)
 * 3. Валидация CRC16 контрольной суммы
 * 4. Парсинг полезных данных в зависимости от типа пакета
 * 5. Возврат структурированного массива с распарсенными данными
 */
class WialonParser
{
    private $data;
    private $position = 0;
    
    /**
     * Конструктор парсера
     * @param string $hexData - hex-строка с данными пакета
     */
    public function __construct($hexData = '')
    {
        $this->data = $this->hexToBinary($hexData);
        $this->position = 0;
    }
    
    /**
     * Читает example.dat файл и конвертирует в бинарные данные
     * @param string $filename - путь к файлу
     * @return string - бинарные данные
     */
    public static function readExampleFile($filename)
    {
        if (!file_exists($filename)) {
            throw new \Exception("Файл {$filename} не найден");
        }
        
        $content = file_get_contents($filename);
        $hexData = preg_replace('/\s+/', '', trim($content));
        
        return self::hexToBinary($hexData);
    }
    
    /**
     * Конвертирует hex-строку в бинарные данные
     * @param string $hex - hex-строка
     * @return string - бинарные данные
     */
    public static function hexToBinary($hex)
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $hex);
        if (strlen($hex) % 2 !== 0) {
            throw new \Exception("Неверный формат hex-данных");
        }
        
        $binary = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $binary .= chr(hexdec(substr($hex, $i, 2)));
        }
        
        return $binary;
    }
    
    /**
     * Преобразует бинарную строку в hex с пробелами между байтами
     * @param string $binary - бинарная строка
     * @return string - hex-строка с пробелами
     */
    public static function binaryToHex($binary)
    {
        $hex = '';
        for ($i = 0; $i < strlen($binary); $i++) {
            $hex .= sprintf('%02x ', ord($binary[$i]));
        }
        return rtrim($hex);
    }
    
    /**
     * Таблица для расчета CRC-16/ARC
     */
    private array $crc16table = [
        0x0000,0xC0C1,0xC181,0x0140,0xC301,0x03C0,0x0280,0xC241,
        0xC601,0x06C0,0x0780,0xC741,0x0500,0xC5C1,0xC481,0x0440,
        0xCC01,0x0CC0,0x0D80,0xCD41,0x0F00,0xCFC1,0xCE81,0x0E40,
        0x0A00,0xCAC1,0xCB81,0x0B40,0xC901,0x09C0,0x0880,0xC841,
        0xD801,0x18C0,0x1980,0xD941,0x1B00,0xDBC1,0xDA81,0x1A40,
        0x1E00,0xDEC1,0xDF81,0x1F40,0xDD01,0x1DC0,0x1C80,0xDC41,
        0x1400,0xD4C1,0xD581,0x1540,0xD701,0x17C0,0x1680,0xD641,
        0xD201,0x12C0,0x1380,0xD341,0x1100,0xD1C1,0xD081,0x1040,
        0xF001,0x30C0,0x3180,0xF141,0x3300,0xF3C1,0xF281,0x3240,
        0x3600,0xF6C1,0xF781,0x3740,0xF501,0x35C0,0x3480,0xF441,
        0x3C00,0xFCC1,0xFD81,0x3D40,0xFF01,0x3FC0,0x3E80,0xFE41,
        0xFA01,0x3AC0,0x3B80,0xFB41,0x3900,0xF9C1,0xF881,0x3840,
        0x2800,0xE8C1,0xE981,0x2940,0xEB01,0x2BC0,0x2A80,0xEA41,
        0xEE01,0x2EC0,0x2F80,0xEF41,0x2D00,0xEDC1,0xEC81,0x2C40,
        0xE401,0x24C0,0x2580,0xE541,0x2700,0xE7C1,0xE681,0x2640,
        0x2200,0xE2C1,0xE381,0x2340,0xE101,0x21C0,0x2080,0xE041,
        0xA001,0x60C0,0x6180,0xA141,0x6300,0xA3C1,0xA281,0x6240,
        0x6600,0xA6C1,0xA781,0x6740,0xA501,0x65C0,0x6480,0xA441,
        0x6C00,0xACC1,0xAD81,0x6D40,0xAF01,0x6FC0,0x6E80,0xAE41,
        0xAA01,0x6AC0,0x6B80,0xAB41,0x6900,0xA9C1,0xA881,0x6840,
        0x7800,0xB8C1,0xB981,0x7940,0xBB01,0x7BC0,0x7A80,0xBA41,
        0xBE01,0x7EC0,0x7F80,0xBF41,0x7D00,0xBDC1,0xBC81,0x7C40,
        0xB401,0x74C0,0x7580,0xB541,0x7700,0xB7C1,0xB681,0x7640,
        0x7200,0xB2C1,0xB381,0x7340,0xB101,0x71C0,0x7080,0xB041,
        0x5000,0x90C1,0x9181,0x5140,0x9301,0x53C0,0x5280,0x9241,
        0x9601,0x56C0,0x5780,0x9741,0x5500,0x95C1,0x9481,0x5440,
        0x9C01,0x5CC0,0x5D80,0x9D41,0x5F00,0x9FC1,0x9E81,0x5E40,
        0x5A00,0x9AC1,0x9B81,0x5B40,0x9901,0x59C0,0x5880,0x9841,
        0x8801,0x48C0,0x4980,0x8941,0x4B00,0x8BC1,0x8A81,0x4A40,
        0x4E00,0x8EC1,0x8F81,0x4F40,0x8D01,0x4DC0,0x4C80,0x8C41,
        0x4400,0x84C1,0x8581,0x4540,0x8701,0x47C0,0x4680,0x8641,
        0x8201,0x42C0,0x4380,0x8341,0x4100,0x81C1,0x8081,0x4040
    ];
    
    /**
     * Расчет CRC-16/ARC
     * @param string $buffer - данные для расчета
     * @return int - CRC-16
     */
    private function crc16Calc(string $buffer): int
    {
        $crc16 = 0;
        $length = strlen($buffer);

        for ($i = 0; $i < $length; $i++) {
            $dataByte = ord($buffer[$i]);
            $crc16 = $this->addCRC($crc16, $dataByte);
        }

        return $crc16;
    }

    /**
     * Добавление байта к CRC
     * @param int $crc - текущий CRC
     * @param int $dataByte - байт данных
     * @return int - новый CRC
     */
    private function addCRC(int $crc, int $dataByte): int
    {
        $index = ($crc & 0xFF) ^ $dataByte;
        $crc16int = $this->crc16table[$index];
        return ($crc >> 8) ^ $crc16int;
    }
    
    /**
     * Устанавливает данные для парсинга
     * @param string $hexData - hex-строка с данными
     */
    public function setData($hexData)
    {
        $this->data = $this->hexToBinary($hexData);
        $this->position = 0;
    }
    
    /**
     * Читает байт из текущей позиции
     * @return int
     */
    private function readByte()
    {
        if ($this->position >= strlen($this->data)) {
            throw new \Exception("Достигнут конец данных");
        }
        return ord($this->data[$this->position++]);
    }
    
    /**
     * Читает 2 байта (unsigned short, big-endian)
     * @return int
     */
    private function readShort()
    {
        $byte1 = $this->readByte();
        $byte2 = $this->readByte();
        return ($byte1 << 8) | $byte2;
    }
    
    /**
     * Читает 4 байта (unsigned int, big-endian)
     * @return int
     */
    private function readInt()
    {
        $byte1 = $this->readByte();
        $byte2 = $this->readByte();
        $byte3 = $this->readByte();
        $byte4 = $this->readByte();
        return ($byte1 << 24) | ($byte2 << 16) | ($byte3 << 8) | $byte4;
    }
    
    /**
     * Читает 8 байт (unsigned long, big-endian)
     * @return int
     */
    private function readLong()
    {
        $high = $this->readInt();
        $low = $this->readInt();
        return ($high << 32) | $low;
    }
    
    /**
     * Читает строку до нулевого байта
     * @return string
     */
    private function readString()
    {
        $result = '';
        while ($this->position < strlen($this->data)) {
            $byte = $this->readByte();
            if ($byte === 0) {
                break;
            }
            $result .= chr($byte);
        }
        return $result;
    }
    
    /**
     * Читает расширяемое поле (1-2 байта)
     * @return int
     */
    private function readExtendedField()
    {
        $byte = $this->readByte();
        if ($byte & 0x80) {
            // Есть второй байт
            $byte2 = $this->readByte();
            return (($byte & 0x7F) << 8) | $byte2;
        }
        return $byte;
    }
    
    /**
     * Читает расширяемое поле (2-4 байта)
     * @return int
     */
    private function readExtendedField2()
    {
        $short = $this->readShort();
        if ($short & 0x8000) {
            // Есть дополнительные 2 байта
            $short2 = $this->readShort();
            return (($short & 0x7FFF) << 16) | $short2;
        }
        return $short;
    }
    
    /**
     * Вычисляет CRC16 контрольную сумму
     * @param string $data - данные для расчета
     * @return int
     */
    
    /**
     * Парсит пакет Login
     * @return array
     */
    private function parseLoginPacket()
    {
        $result = [
            'type' => 'Login',
            'protocol_version' => $this->readExtendedField(),
            'flags' => $this->readByte(),
            'id' => null,
            'password' => null
        ];
        
        // Парсинг ID
        $idType = ($result['flags'] >> 4) & 0x0F;
        switch ($idType) {
            case 1: // unsigned short
                $result['id'] = $this->readShort();
                break;
            case 2: // unsigned int
                $result['id'] = $this->readInt();
                break;
            case 3: // unsigned long
                $result['id'] = $this->readLong();
                break;
            case 4: // String
                $result['id'] = $this->readString();
                break;
        }
        
        // Парсинг пароля
        $pwdType = $result['flags'] & 0x0F;
        switch ($pwdType) {
            case 0: // Пароль отсутствует
                break;
            case 1: // unsigned short
                $result['password'] = $this->readShort();
                break;
            case 2: // unsigned int
                $result['password'] = $this->readInt();
                break;
            case 3: // unsigned long
                $result['password'] = $this->readLong();
                break;
            case 4: // String
                $result['password'] = $this->readString();
                break;
        }
        
        return $result;
    }
    
    /**
     * Парсит пакет Data
     * @param int $dataLength - длина данных
     * @return array
     */
    private function parseDataPacket($dataLength)
    {
        $result = [
            'type' => 'Data',
            'messages' => []
        ];
        
        $dataStart = $this->position;
        
        while ($this->position < $dataStart + $dataLength) {
            $message = [
                'time' => $this->readInt(),
                'count' => $this->readByte(),
                'subrecords' => []
            ];
            
            for ($i = 0; $i < $message['count']; $i++) {
                $subrecordType = $this->readExtendedField();
                $subrecord = $this->parseSubrecord($subrecordType);
                $message['subrecords'][] = $subrecord;
            }
            
            $result['messages'][] = $message;
        }
        
        return $result;
    }
    
    /**
     * Парсит подзапись по типу
     * @param int $type - тип подзаписи
     * @return array
     */
    private function parseSubrecord($type)
    {
        switch ($type) {
            case 0: // Custom Parameters
                return $this->parseCustomParameters();
            case 1: // Position Data
                return $this->parsePositionData();
            case 3: // Picture
                return $this->parsePicture();
            case 4: // LBS Parameters
                return $this->parseLBSParameters();
            default:
                return ['type' => $type, 'data' => 'Unsupported subrecord type'];
        }
    }
    
    /**
     * Парсит Custom Parameters
     * @return array
     */
    private function parseCustomParameters()
    {
        $result = [
            'type' => 'Custom Parameters',
            'count' => $this->readExtendedField(),
            'parameters' => []
        ];
        
        for ($i = 0; $i < $result['count']; $i++) {
            $param = [
                'sensor_number' => $this->readExtendedField(),
                'sensor_type' => $this->readByte(),
                'value' => null,
                'byte' => null
            ];
            
            // Парсинг значения по типу
            $dataType = $param['sensor_type'] & 0x1F;
            $scale = ($param['sensor_type'] >> 5) & 0x07;
            
            switch ($dataType) {
                case 0: // unsigned byte
                    $param['byte'] = $this->readByte();
                    $param['value'] = $param['byte'];
                    break;
                case 1: // unsigned short
                    $param['byte'] = $this->readShort();
                    $param['value'] = $param['byte'];
                    break;
                case 2: // unsigned int
                    $param['byte'] = $this->readInt();
                    $param['value'] = $param['byte'];
                    break;
                case 3: // unsigned long
                    $param['byte'] = $this->readLong();
                    $param['value'] = $param['byte'];
                    break;
                case 4: // signed byte
                    $param['byte'] = $this->readByte();
                    $param['value'] = $param['byte'];
                    if ($param['value'] > 127) $param['value'] -= 256;
                    break;
                case 5: // signed int
                    $param['byte'] = $this->readInt();
                    $param['value'] = $param['byte'];
                    if ($param['value'] > 2147483647) $param['value'] -= 4294967296;
                    break;
                case 6: // signed short
                    $param['byte'] = $this->readShort();
                    $param['value'] = $param['byte'];
                    if ($param['value'] > 32767) $param['value'] -= 65536;
                    break;
                case 7: // signed long
                    $param['byte'] = $this->readLong();
                    $param['value'] = $param['byte'];
                    break;
                case 8: // float
                    $param['byte'] = $this->readBytes(4);
                    $param['value'] = unpack('G', $param['byte'])[1];
                    break;
                case 9: // double
                    $param['byte'] = $this->readBytes(8);
                    $param['value'] = unpack('E', $param['byte'])[1];
                    break;
                case 10: // String
                    $param['byte'] = $this->readString();
                    $param['value'] = $param['byte'];
                    break;
            }
            
            // Применяем масштабирование
            if ($scale > 0 && $dataType < 8) {
                $param['value'] = $param['value'] / pow(10, $scale);
            }
            
            $result['parameters'][] = $param;
        }
        
        return $result;
    }
    
    /**
     * Парсит Position Data
     * @return array
     */
    private function parsePositionData()
    {
        return [
            'type' => 'Position Data',
            'latitude' => $this->readInt() / 1000000.0,
            'longitude' => $this->readInt() / 1000000.0,
            'speed' => $this->readShort(),
            'course' => $this->readShort(),
            'height' => $this->readShort(),
            'satellites' => $this->readByte(),
            'hdop' => $this->readShort() / 100.0
        ];
    }
    
    /**
     * Парсит Picture
     * @return array
     */
    private function parsePicture()
    {
        $index = $this->readExtendedField();
        $length = $this->readExtendedField2();
        $count = $this->readExtendedField();
        $name = $this->readString();
        
        $result = [
            'type' => 'Picture',
            'index' => $index,
            'length' => $length,
            'count' => $count,
            'name' => $name,
            'data' => $this->readBytes($length)
        ];
        
        return $result;
    }
    
    /**
     * Парсит LBS Parameters
     * @return array
     */
    private function parseLBSParameters()
    {
        $result = [
            'type' => 'LBS Parameters',
            'count' => $this->readByte(),
            'parameters' => []
        ];
        
        for ($i = 0; $i < $result['count']; $i++) {
            $result['parameters'][] = [
                'mcc' => $this->readShort(),
                'mnc' => $this->readShort(),
                'lac' => $this->readShort(),
                'cell_id' => $this->readShort(),
                'rx_level' => $this->readShort(),
                'ta' => $this->readShort()
            ];
        }
        
        return $result;
    }
    
    /**
     * Читает указанное количество байт
     * @param int $length - количество байт
     * @return string
     */
    private function readBytes($length)
    {
        if ($this->position + $length > strlen($this->data)) {
            throw new \Exception("Недостаточно данных для чтения");
        }
        
        $result = substr($this->data, $this->position, $length);
        $this->position += $length;
        return $result;
    }
    
    /**
     * Генерирует ответ сервера на пакет
     * @param int $code - код ответа (0-4, 255)
     * @param int $seq - порядковый номер пакета
     * @return array
     */
    public function generateServerResponse($code = 0, $seq = 0)
    {
        return [
            'head' => '0x4040',
            'code' => $code,
            'sequence' => $seq,
            'hex' => "\x40\x40" . pack('C', $code) . pack('n', $seq),
            'description' => $this->getResponseDescription($code)
        ];
    }
    
    /**
     * Получает описание кода ответа сервера
     * @param int $code - код ответа
     * @return string
     */
    private function getResponseDescription($code)
    {
        $descriptions = [
            0 => 'Пакет успешно зарегистрирован',
            1 => 'Ошибка авторизации',
            2 => 'Неверный пароль',
            3 => 'Пакет не зарегистрирован',
            4 => 'Ошибка CRC',
            255 => 'Команда на устройство'
        ];
        
        return isset($descriptions[$code]) ? $descriptions[$code] : 'Неизвестный код ответа';
    }
    
    /**
     * Преобразует данные пакета в отдельные записи
     * @param array $data - данные пакета
     * @return array
     */
    private function convertToRecords($data)
    {
        $records = [];
        
        if ($data['type'] === 'Data' && isset($data['messages'])) {
            foreach ($data['messages'] as $message) {
                foreach ($message['subrecords'] as $subrecord) {
                    if ($subrecord['type'] === 'Position Data') {
                        $records[] = [
                            'time' => $message['time'],
                            'type' => 'Position Data',
                            'latitude' => $subrecord['latitude'],
                            'longitude' => $subrecord['longitude'],
                            'speed' => $subrecord['speed'],
                            'course' => $subrecord['course'],
                            'height' => $subrecord['height'],
                            'satellites' => $subrecord['satellites'],
                            'hdop' => $subrecord['hdop']
                        ];
                    } elseif ($subrecord['type'] === 'Custom Parameters') {
                        foreach ($subrecord['parameters'] as $param) {
                            $records[] = [
                                'time' => $message['time'],
                                'type' => 'Custom Parameters',
                                'sensor' => $param['sensor_number'],
                                'sensor_type' => $param['sensor_type'],
                                'value' => $param['value']
                                // 'byte' => $param['byte'] // Временно закомментировано из-за бинарных данных
                            ];
                        }
                    } elseif ($subrecord['type'] === 'Picture') {
                        $records[] = [
                            'time' => $message['time'],
                            'type' => 'Picture',
                            'index' => $subrecord['index'],
                            'length' => $subrecord['length'],
                            'count' => $subrecord['count'],
                            'name' => $subrecord['name']
                        ];
                    } elseif ($subrecord['type'] === 'LBS Parameters') {
                        foreach ($subrecord['parameters'] as $param) {
                            $records[] = [
                                'time' => $message['time'],
                                'type' => 'LBS Parameters',
                                'mcc' => $param['mcc'],
                                'mnc' => $param['mnc'],
                                'lac' => $param['lac'],
                                'cell_id' => $param['cell_id'],
                                'rx_level' => $param['rx_level'],
                                'ta' => $param['ta']
                            ];
                        }
                    }
                }
            }
        } elseif ($data['type'] === 'Login') {
            $records[] = [
                'type' => 'Login',
                'protocol_version' => $data['protocol_version'],
                'flags' => $data['flags'],
                'id' => $data['id'],
                'password' => $data['password']
            ];
        } elseif ($data['type'] === 'Keep-Alive') {
            $records[] = [
                'type' => 'Keep-Alive'
            ];
        }
        
        return $records;
    }
    
    /**
     * Основной метод парсинга пакета
     * @return array
     */
    public function parse()
    {
        if (strlen($this->data) < 7) {
            throw new \Exception("Недостаточно данных для парсинга");
        }
        
        $this->position = 0;
        
        // Парсинг заголовка
        $head = $this->readShort();
        if ($head !== 0x2424) {
            throw new \Exception("Неверный заголовок пакета: 0x" . dechex($head));
        }
        
        $type = $this->readExtendedField();
        $seq = $this->readShort();
        $length = $this->readExtendedField2();
        
        $result = [
            'head' => '0x' . dechex($head),
            'type' => $type,
            'sequence' => $seq,
            'length' => $length,
            'data' => null,
            'records' => [],
            'server_response' => null
        ];
        
        // Парсинг данных в зависимости от типа
        switch ($type) {
            case 0: // Login
                $result['data'] = $this->parseLoginPacket();
                break;
            case 1: // Data
                $result['data'] = $this->parseDataPacket($length);
                break;
            case 2: // Keep-Alive
                $result['data'] = ['type' => 'Keep-Alive'];
                break;
            default:
                $result['data'] = ['type' => 'Unknown', 'raw_data' => $this->readBytes($length)];
        }
        
        // Проверка CRC16
        $crcValid = true;
        if (strlen($this->data) >= $this->position + 2) {
            $crc = $this->readShort();
            $dataForCrc = substr($this->data, 0, strlen($this->data) - 2);
            $calculatedCrc = $this->crc16Calc($dataForCrc);
            
            $result['crc'] = [
                'received' => $crc,
                'calculated' => $calculatedCrc,
                'valid' => $crc === $calculatedCrc
            ];
            
            $crcValid = $crc === $calculatedCrc;
        }
        
        // Преобразуем данные в отдельные записи
        $result['records'] = $this->convertToRecords($result['data']);
        
        // Генерируем ответ сервера
        $responseCode = $crcValid ? 0 : 4; // 0 = успех, 4 = ошибка CRC
        $result['server_response'] = $this->generateServerResponse($responseCode, $seq);
        
        return $result;
    }
    
    /**
     * Парсит пакет из example.dat файла
     * @param string $filename - путь к файлу
     * @return array
     */
    public static function parseFromFile($filename)
    {
        $parser = new self();
        $parser->data = self::readExampleFile($filename);
        return $parser->parse();
    }
}
