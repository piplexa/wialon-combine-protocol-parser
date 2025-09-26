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
    private static function hexToBinary($hex)
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
    private function calculateCRC16($data)
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x0001) {
                    $crc = ($crc >> 1) ^ 0xA001;
                } else {
                    $crc = $crc >> 1;
                }
            }
        }
        return $crc;
    }
    
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
        if ($this->position < strlen($this->data) - 2) {
            $crc = $this->readShort();
            $dataForCrc = substr($this->data, 0, $this->position - 2);
            $calculatedCrc = $this->calculateCRC16($dataForCrc);
            
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
