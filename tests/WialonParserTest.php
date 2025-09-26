<?php

namespace Wialon\Combine\Tests;

use PHPUnit\Framework\TestCase;
use Wialon\Combine\WialonParser;

class WialonParserTest extends TestCase
{
    private $parser;

    protected function setUp(): void
    {
        $this->parser = new WialonParser();
    }

    public function testParseLoginPacket()
    {
        $hexData = '24 24 00 00 00 00 0a 01 30 00 03 0e 42 59 8b 46 ec 76 7f';
        $this->parser->setData($hexData);
        $result = $this->parser->parse();

        $this->assertEquals('0x2424', $result['head']);
        $this->assertEquals(0, $result['type']);
        $this->assertEquals(0, $result['sequence']);
        $this->assertEquals(10, $result['length']);
        $this->assertTrue(isset($result['crc']) ? $result['crc']['valid'] : true);
        $this->assertEquals('Login', $result['data']['type']);
        $this->assertEquals(1, $result['data']['protocol_version']);
        $this->assertEquals(48, $result['data']['flags']);
        $this->assertEquals(860103063062252, $result['data']['id']);
        $this->assertNull($result['data']['password']);
    }

    public function testParseDataPacket()
    {
        $hexData = '24 24 01 00 01 00 62 64 d0 8e f8 02 01 02 8e 3f 10 02 d6 a8 90 00 00 01 08 ff fd 11 00 40 00 80 10 19 00 00 09 02 00 28 ad ba 0b 00 01 6d 00 0b 6e 00 09 71 08 47 30 83 45 72 00 00 84 d9 00 00 84 e2 08 42 10 00 00 84 e4 08 41 4b 0a 3d 84 e5 08 40 85 c2 8f 87 d5 00 1f 8a f1 00 00 8a f2 00 00 8a f8 00 00 8a f9 00 00 39 ea';
        $this->parser->setData($hexData);
        $result = $this->parser->parse();

        $this->assertEquals('0x2424', $result['head']);
        $this->assertEquals(1, $result['type']);
        $this->assertEquals(1, $result['sequence']);
        $this->assertEquals(98, $result['length']);
        $this->assertTrue(isset($result['crc']) ? $result['crc']['valid'] : true);
        $this->assertEquals('Data', $result['data']['type']);
        $this->assertIsArray($result['data']['messages']);
        $this->assertGreaterThan(0, count($result['data']['messages']));
        $this->assertIsArray($result['records']);
        $this->assertGreaterThan(0, count($result['records']));
    }

    public function testServerResponse()
    {
        $response = $this->parser->generateServerResponse(0, 123);
        
        $this->assertEquals('0x4040', $response['head']);
        $this->assertEquals(0, $response['code']);
        $this->assertEquals(123, $response['sequence']);
        $this->assertEquals('Пакет успешно зарегистрирован', $response['description']);
    }

    public function testHexToBinaryConversion()
    {
        $hexData = '24 24 01 00 02 00 5a 68 9f 10 f9 02 01 02 65 23 68 02 a7 64 0c 00 37 00 2c 03 fc 10 00 3f 00 80 0e 19 00 00 09 02 00 36 05 8e 0b 00 01 6d 00 0d 6e 00 0a 71 08 47 10 e2 7b 72 00 01 84 d9 00 01 84 e2 08 42 48 00 00 84 e4 08 41 49 70 a4 84 e5 08 3f d3 33 33 87 d5 00 14 8a f3 00 03 8b 43 00 03 b8 cf';
        $this->parser->setData($hexData);
        $result = $this->parser->parse();

        $this->assertEquals('0x2424', $result['head']);
        $this->assertEquals(1, $result['type']);
        $this->assertEquals(2, $result['sequence']);
    }

    public function testCustomParametersParsing()
    {
        $hexData = '24 24 01 00 01 00 62 64 d0 8e f8 02 01 02 8e 3f 10 02 d6 a8 90 00 00 01 08 ff fd 11 00 40 00 80 10 19 00 00 09 02 00 28 ad ba 0b 00 01 6d 00 0b 6e 00 09 71 08 47 30 83 45 72 00 00 84 d9 00 00 84 e2 08 42 10 00 00 84 e4 08 41 4b 0a 3d 84 e5 08 40 85 c2 8f 87 d5 00 1f 8a f1 00 00 8a f2 00 00 8a f8 00 00 8a f9 00 00 39 ea';
        $this->parser->setData($hexData);
        $result = $this->parser->parse();

        // Проверяем наличие Custom Parameters записей
        $customParamsFound = false;
        foreach ($result['records'] as $record) {
            if ($record['type'] === 'Custom Parameters') {
                $customParamsFound = true;
                $this->assertArrayHasKey('sensor', $record);
                $this->assertArrayHasKey('sensor_type', $record);
                $this->assertArrayHasKey('value', $record);
                break;
            }
        }
        $this->assertTrue($customParamsFound, 'Custom Parameters records not found');
    }

    public function testPositionDataParsing()
    {
        $hexData = '24 24 01 00 01 00 62 64 d0 8e f8 02 01 02 8e 3f 10 02 d6 a8 90 00 00 01 08 ff fd 11 00 40 00 80 10 19 00 00 09 02 00 28 ad ba 0b 00 01 6d 00 0b 6e 00 09 71 08 47 30 83 45 72 00 00 84 d9 00 00 84 e2 08 42 10 00 00 84 e4 08 41 4b 0a 3d 84 e5 08 40 85 c2 8f 87 d5 00 1f 8a f1 00 00 8a f2 00 00 8a f8 00 00 8a f9 00 00 39 ea';
        $this->parser->setData($hexData);
        $result = $this->parser->parse();

        // Проверяем наличие Position Data записей
        $positionDataFound = false;
        foreach ($result['records'] as $record) {
            if ($record['type'] === 'Position Data') {
                $positionDataFound = true;
                $this->assertArrayHasKey('latitude', $record);
                $this->assertArrayHasKey('longitude', $record);
                $this->assertArrayHasKey('speed', $record);
                $this->assertArrayHasKey('course', $record);
                $this->assertArrayHasKey('height', $record);
                $this->assertArrayHasKey('satellites', $record);
                $this->assertArrayHasKey('hdop', $record);
                break;
            }
        }
        $this->assertTrue($positionDataFound, 'Position Data records not found');
    }

    public function testInvalidPacketHeader()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Неверный заголовок пакета');
        
        $hexData = '25 25 01 00 02 00 5a'; // Неверный заголовок
        $this->parser->setData($hexData);
        $this->parser->parse();
    }

    public function testInsufficientData()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Недостаточно данных для парсинга');
        
        $hexData = '24 24'; // Недостаточно данных
        $this->parser->setData($hexData);
        $this->parser->parse();
    }
}
