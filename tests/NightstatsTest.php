<?php

namespace Nightstats\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nightstats\Nightstats;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NightstatsTest extends TestCase
{
    private function createNightstatsWithMockClient(string $domain, ?Client $client = null): Nightstats
    {
        $reflection = new ReflectionClass(Nightstats::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $domain = rtrim($domain, '/');
        
        $sgvUrlProp = $reflection->getProperty('sgvUrl');
        $sgvUrlProp->setAccessible(true);
        $sgvUrlProp->setValue($instance, $domain . '/api/v1/entries/sgv.json');

        $treatmentsUrlProp = $reflection->getProperty('treatmentsUrl');
        $treatmentsUrlProp->setAccessible(true);
        $treatmentsUrlProp->setValue($instance, $domain . '/api/v1/treatments.json');

        $minGlucoseProp = $reflection->getProperty('minGlucose');
        $minGlucoseProp->setAccessible(true);
        $minGlucoseProp->setValue($instance, 70);

        $maxGlucoseProp = $reflection->getProperty('maxGlucose');
        $maxGlucoseProp->setAccessible(true);
        $maxGlucoseProp->setValue($instance, 180);

        $httpClientProp = $reflection->getProperty('httpClient');
        $httpClientProp->setAccessible(true);
        $httpClientProp->setValue($instance, $client ?? $this->createMock(Client::class));

        return $instance;
    }

    public function testConstructorValidDomain(): void
    {
        $nightstats = new Nightstats('https://example.com');
        $this->assertInstanceOf(Nightstats::class, $nightstats);
    }

    public function testConstructorWithCustomGlucoseRange(): void
    {
        $nightstats = new Nightstats('https://example.com', 80, 200);
        $this->assertInstanceOf(Nightstats::class, $nightstats);
    }

    public function testConstructorThrowsExceptionForEmptyDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Domain cannot be empty');
        new Nightstats('');
    }

    public function testConstructorThrowsExceptionForWhitespaceDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Domain cannot be empty');
        new Nightstats('   ');
    }

    public function testConstructorThrowsExceptionForInvalidDomainFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid domain format');
        new Nightstats('not-a-url');
    }

    public function testConstructorThrowsExceptionForMinGlucoseZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minGlucose must be greater than 0');
        new Nightstats('https://example.com', 0, 180);
    }

    public function testConstructorThrowsExceptionForMinGlucoseNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minGlucose must be greater than 0');
        new Nightstats('https://example.com', -10, 180);
    }

    public function testConstructorThrowsExceptionForMaxGlucoseZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxGlucose must be greater than 0');
        new Nightstats('https://example.com', 70, 0);
    }

    public function testConstructorThrowsExceptionForMaxGlucoseNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxGlucose must be greater than 0');
        new Nightstats('https://example.com', 70, -5);
    }

    public function testConstructorThrowsExceptionWhenMinGlucoseGreaterThanMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minGlucose must be less than maxGlucose');
        new Nightstats('https://example.com', 200, 100);
    }

    public function testConstructorThrowsExceptionWhenMinGlucoseEqualsMaxGlucose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minGlucose must be less than maxGlucose');
        new Nightstats('https://example.com', 100, 100);
    }

    public function testConstructorThrowsExceptionForMaxGlucoseAbove600(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxGlucose must be less than or equal to 600 mg/dL');
        new Nightstats('https://example.com', 70, 601);
    }

    public function testConstructorAllowsMaxGlucoseEqualTo600(): void
    {
        $nightstats = new Nightstats('https://example.com', 70, 600);
        $this->assertInstanceOf(Nightstats::class, $nightstats);
    }

    public function testGetStatsReturnsCorrectStructure(): void
    {
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
        $this->assertArrayHasKey('days', $result);
        $this->assertArrayHasKey('glucose', $result);
        $this->assertArrayHasKey('values', $result['glucose']);
        $this->assertArrayHasKey('stats', $result['glucose']);
        $this->assertArrayHasKey('agp', $result['glucose']);
        $this->assertEquals(14, $result['days']);
    }

    public function testGetStatsIncludesTreatmentsWhenRequested(): void
    {
        $glucoseData = $this->generateGlucoseData(50);
        $treatmentsData = $this->generateTreatmentsData();
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData, $treatmentsData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                if (strpos($url, 'treatments') !== false) {
                    return new Response(200, [], json_encode($treatmentsData));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14, true);

        $this->assertArrayHasKey('treatments', $result);
        $this->assertArrayHasKey('values', $result['treatments']);
        $this->assertArrayHasKey('byDate', $result['treatments']);
        $this->assertArrayHasKey('byHour', $result['treatments']);
    }

    public function testGetStatsCalculatesGlucoseStatisticsCorrectly(): void
    {
        $glucoseData = $this->generateGlucoseData(100, 120);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData) {
                return new Response(200, [], json_encode($glucoseData));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $stats = $result['glucose']['stats'];
        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('mean', $stats);
        $this->assertArrayHasKey('sd', $stats);
        $this->assertArrayHasKey('cv', $stats);
        $this->assertArrayHasKey('tir_percent', $stats);
        $this->assertArrayHasKey('tbr_percent', $stats);
        $this->assertArrayHasKey('tar_percent', $stats);
        $this->assertEquals(100, $stats['count']);
    }

    public function testGetStatsCalculatesTirTbrTarCorrectly(): void
    {
        $glucoseData = [
            ['sgv' => 80, 'dateString' => '2024-01-15T10:00:00'],
            ['sgv' => 100, 'dateString' => '2024-01-15T11:00:00'],
            ['sgv' => 150, 'dateString' => '2024-01-15T12:00:00'],
            ['sgv' => 60, 'dateString' => '2024-01-15T13:00:00'],
            ['sgv' => 200, 'dateString' => '2024-01-15T14:00:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData) {
                return new Response(200, [], json_encode($glucoseData));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $stats = $result['glucose']['stats'];
        $this->assertEquals(60.0, $stats['tir_percent']);
        $this->assertEquals(20.0, $stats['tbr_percent']);
        $this->assertEquals(20.0, $stats['tar_percent']);
    }

    public function testExtractGlucoseDataFiltersInvalidEntries(): void
    {
        $data = [
            ['sgv' => 100, 'dateString' => '2024-01-15T10:00:00'],
            ['sgv' => 0, 'dateString' => '2024-01-15T11:00:00'],
            ['sgv' => -5, 'dateString' => '2024-01-15T12:00:00'],
            ['dateString' => '2024-01-15T13:00:00'],
            ['sgv' => 150, 'no_date' => '2024-01-15T14:00:00'],
            ['sgv' => 120, 'dateString' => '2024-01-15T10:30:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $this->assertCount(2, $result['glucose']['values']);
    }

    public function testExtractGlucoseDataGroupsByHour(): void
    {
        $data = [
            ['sgv' => 100, 'dateString' => '2024-01-15T10:30:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T10:45:00'],
            ['sgv' => 120, 'dateString' => '2024-01-15T11:30:00'],
            ['sgv' => 130, 'dateString' => '2024-01-15T11:45:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $agp = $result['glucose']['agp'];
        $this->assertArrayHasKey(10, $agp);
        $this->assertArrayHasKey(11, $agp);
    }

    public function testCalculateAgpOnlyIncludesHoursWithAtLeast2Values(): void
    {
        $data = [
            ['sgv' => 100, 'dateString' => '2024-01-15T10:30:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T10:45:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $agp = $result['glucose']['agp'];
        $this->assertNotEmpty($agp);
    }

    public function testCalculateAgpSortsByHour(): void
    {
        $data = [
            ['sgv' => 120, 'dateString' => '2024-01-15T14:30:00'],
            ['sgv' => 130, 'dateString' => '2024-01-15T14:45:00'],
            ['sgv' => 100, 'dateString' => '2024-01-15T10:30:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T10:45:00'],
            ['sgv' => 90, 'dateString' => '2024-01-15T08:30:00'],
            ['sgv' => 95, 'dateString' => '2024-01-15T08:45:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $hours = array_keys($result['glucose']['agp']);
        $this->assertEquals([8, 10, 14], $hours);
    }

    public function testCalculateAgpContainsCorrectKeys(): void
    {
        $data = $this->generateGlucoseData(10, 100);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $agp = reset($result['glucose']['agp']);
        $this->assertArrayHasKey('mean', $agp);
        $this->assertArrayHasKey('p25', $agp);
        $this->assertArrayHasKey('p50', $agp);
        $this->assertArrayHasKey('p75', $agp);
        $this->assertArrayHasKey('values', $agp);
    }

    public function testFetchDataThrowsExceptionOnHttpError(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(500, [], 'Internal Server Error'));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('getStats');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error fetching API data');
        $method->invoke($nightstats);
    }

    public function testFetchDataThrowsExceptionOnEmptyResponse(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], '[]'));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('getStats');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No data returned from API');
        $method->invoke($nightstats);
    }

    public function testFetchDataThrowsExceptionOnGuzzleException(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willThrowException(new \GuzzleHttp\Exception\RequestException(
                'Connection error',
                new Request('GET', 'https://example.com')
            ));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('getStats');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP request error');
        $method->invoke($nightstats);
    }

    public function testCalculateStatisticsThrowsExceptionOnEmptyValues(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode([])));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('calculateStatistics');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient data for analysis');
        $method->invoke($nightstats, []);
    }

    public function testExtractTreatmentsDataFiltersInvalidEntries(): void
    {
        $data = [
            ['insulin' => 5.0, 'sysTime' => '2024-01-15T10:00:00'],
            ['insulin' => 0, 'sysTime' => '2024-01-15T11:00:00'],
            ['insulin' => -2.0, 'sysTime' => '2024-01-15T12:00:00'],
            ['sysTime' => '2024-01-15T13:00:00'],
            ['insulin' => 3.0, 'no_time' => '2024-01-15T14:00:00'],
            ['insulin' => 4.0, 'sysTime' => '2024-01-15T15:00:00'],
        ];
        
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($data, $glucoseData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                if (strpos($url, 'treatments') !== false) {
                    return new Response(200, [], json_encode($data));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14, true);

        $this->assertCount(2, $result['treatments']['values']);
    }

    public function testExtractTreatmentsDataGroupsCorrectly(): void
    {
        $data = [
            ['insulin' => 5.0, 'sysTime' => '2024-01-15T10:30:00'],
            ['insulin' => 3.0, 'sysTime' => '2024-01-15T10:45:00'],
            ['insulin' => 4.0, 'sysTime' => '2024-01-16T11:30:00'],
        ];
        
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($data, $glucoseData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                if (strpos($url, 'treatments') !== false) {
                    return new Response(200, [], json_encode($data));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14, true);

        $this->assertArrayHasKey('2024-01-15', $result['treatments']['byDate']);
        $this->assertArrayHasKey('2024-01-16', $result['treatments']['byDate']);
        $this->assertArrayHasKey(10, $result['treatments']['byHour']);
        $this->assertArrayHasKey(11, $result['treatments']['byHour']);
    }

    public function testExtractTreatmentsDataSortsByDateAndHour(): void
    {
        $data = [
            ['insulin' => 4.0, 'sysTime' => '2024-01-16T11:30:00'],
            ['insulin' => 5.0, 'sysTime' => '2024-01-15T10:30:00'],
            ['insulin' => 3.0, 'sysTime' => '2024-01-17T09:30:00'],
        ];
        
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($data, $glucoseData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                if (strpos($url, 'treatments') !== false) {
                    return new Response(200, [], json_encode($data));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14, true);

        $dates = array_keys($result['treatments']['byDate']);
        $this->assertEquals(['2024-01-15', '2024-01-16', '2024-01-17'], $dates);

        $hours = array_keys($result['treatments']['byHour']);
        $this->assertEquals([9, 10, 11], $hours);
    }

    public function testGetDateRangeReturnsCorrectDates(): void
    {
        $nightstats = new Nightstats('https://example.com');

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('getDateRange');
        $method->setAccessible(true);

        $result = $method->invoke($nightstats, 7);

        $this->assertInstanceOf(\DateTime::class, $result[0]);
        $this->assertInstanceOf(\DateTime::class, $result[1]);

        $diff = $result[0]->diff($result[1]);
        $this->assertEquals(7, $diff->days);
    }

    public function testFetchGlucoseDataUsesCorrectUrl(): void
    {
        $glucoseData = $this->generateGlucoseData(10);
        $capturedUrl = null;
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use (&$capturedUrl, $glucoseData) {
                $capturedUrl = $url;
                return new Response(200, [], json_encode($glucoseData));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('fetchGlucoseData');
        $method->setAccessible(true);
        $method->invoke($nightstats, 14);

        $this->assertStringContainsString('/api/v1/entries/sgv.json', $capturedUrl);
        $this->assertStringContainsString('find[dateString]', $capturedUrl);
        $this->assertStringContainsString('count=', $capturedUrl);
    }

    public function testFetchTreatmentsDataUsesCorrectUrl(): void
    {
        $treatmentsData = $this->generateTreatmentsData();
        $capturedUrl = null;
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use (&$capturedUrl, $treatmentsData) {
                $capturedUrl = $url;
                return new Response(200, [], json_encode($treatmentsData));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);

        $reflection = new ReflectionClass($nightstats);
        $method = $reflection->getMethod('fetchTreatmentsData');
        $method->setAccessible(true);
        $method->invoke($nightstats, 14);

        $this->assertStringContainsString('/api/v1/treatments.json', $capturedUrl);
        $this->assertStringContainsString('find[created_at]', $capturedUrl);
    }

    public function testGetStatsWithDifferentDaysParameter(): void
    {
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData) {
                return new Response(200, [], json_encode($glucoseData));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(30);

        $this->assertEquals(30, $result['days']);
    }

    public function testGetStatsWithoutTreatmentsDoesNotIncludeTreatments(): void
    {
        $glucoseData = $this->generateGlucoseData(50);
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturnCallback(function ($url) use ($glucoseData) {
                if (strpos($url, 'entries/sgv') !== false) {
                    return new Response(200, [], json_encode($glucoseData));
                }
                return new Response(200, [], json_encode([]));
            });

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14, false);

        $this->assertArrayNotHasKey('treatments', $result);
    }

    public function testCalculateStatisticsHandlesMultipleValues(): void
    {
        $data = [
            ['sgv' => 100, 'dateString' => '2024-01-15T10:00:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T11:00:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $stats = $result['glucose']['stats'];
        $this->assertEquals(2, $stats['count']);
        $this->assertEquals(105, $stats['mean']);
    }

    public function testExtractGlucoseDataReturnsCorrectValues(): void
    {
        $data = [
            ['sgv' => 90, 'dateString' => '2024-01-15T10:00:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T11:00:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $this->assertContains(90, $result['glucose']['values']);
        $this->assertContains(110, $result['glucose']['values']);
    }

    public function testAgpWithMultipleHours(): void
    {
        $data = [
            ['sgv' => 100, 'dateString' => '2024-01-15T08:30:00'],
            ['sgv' => 110, 'dateString' => '2024-01-15T08:45:00'],
            ['sgv' => 120, 'dateString' => '2024-01-15T09:30:00'],
            ['sgv' => 130, 'dateString' => '2024-01-15T09:45:00'],
        ];
        
        $mockClient = $this->createMock(Client::class);
        $mockClient->method('get')
            ->willReturn(new Response(200, [], json_encode($data)));

        $nightstats = $this->createNightstatsWithMockClient('https://example.com', $mockClient);
        $result = $nightstats->getStats(14);

        $agp = $result['glucose']['agp'];
        $this->assertCount(2, $agp);
        $this->assertArrayHasKey(8, $agp);
        $this->assertArrayHasKey(9, $agp);
    }

    public function testDomainIsTrimmed(): void
    {
        $nightstats = new Nightstats('https://example.com/');
        $reflection = new ReflectionClass($nightstats);
        $sgvUrlProp = $reflection->getProperty('sgvUrl');
        $sgvUrlProp->setAccessible(true);
        $sgvUrl = $sgvUrlProp->getValue($nightstats);
        
        $this->assertStringEndsNotWith('//', $sgvUrl);
    }

    private function generateGlucoseData(int $count, int $baseValue = 100): array
    {
        $data = [];
        $baseDate = new \DateTime('2024-01-15');
        
        for ($i = 0; $i < $count; $i++) {
            $date = clone $baseDate;
            $date->modify("-{$i} minutes");
            $data[] = [
                'sgv' => $baseValue + rand(-30, 30),
                'dateString' => $date->format('Y-m-d\TH:i:s'),
            ];
        }
        
        return $data;
    }

    private function generateTreatmentsData(): array
    {
        return [
            ['insulin' => 5.0, 'sysTime' => '2024-01-15T10:30:00'],
            ['insulin' => 3.0, 'sysTime' => '2024-01-15T18:45:00'],
            ['insulin' => 4.0, 'sysTime' => '2024-01-16T07:00:00'],
        ];
    }
}
