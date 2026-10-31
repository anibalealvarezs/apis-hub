<?php

namespace Tests\Unit\Services\Analytics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Services\Analytics\ScopedReportService;
use Services\Analytics\ScopedReportStore;

final class ScopedReportServiceTest extends TestCase
{
    public function testCatalogAndWindowReportsAreQueuedPropertyBoundAndAuthorizationScoped(): void
    {
        $directory = sys_get_temp_dir().'/analytics-service-test-'.bin2hex(random_bytes(8));
        $store = new ScopedReportStore($directory);
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnCallback(fn ($sql, $params) => $params === [7, 'fixture'] ? ['id' => 7, 'platform_id' => '123'] : false);
        $provider = new class {
            public int $calls = 0;
            public function fetchAnalyticsCatalog(array $config): array {
                $this->calls++;
                return ['metadata' => ['metrics' => [['apiName' => 'totalUsers']]], 'catalog' => ['context' => ['property_id' => '123', 'time_zone' => 'UTC', 'currency' => 'USD']]];
            }
            public static function compileAnalyticsDefinition(array $definition, array $metadata, array $context): array {
                return ['context' => $context, 'kind' => 'window', 'payload' => $definition, 'hash' => hash('sha256', json_encode([$definition, $context]))];
            }
            public function executeAnalyticsReport(array $config, array $definition): array {
                $this->calls++;
                TestCase::assertSame('123', $config['platform_id']);
                return ['metricHeaders' => [['name' => 'totalUsers']], 'rows' => [['metricValues' => [['value' => '5']]]], 'metadata' => ['timeZone' => 'UTC', 'subjectToThresholding' => true]];
            }
        };
        $service = new ScopedReportService($store, $db, fn () => $provider);
        try {
            self::assertSame('pending', $service->catalog('fixture', 7, 'actor')['state']);
            self::assertSame(0, $provider->calls, 'HTTP catalog reads must not contact the source.');
            $service->process();
            $definition = ['start_date' => '2026-10-01', 'end_date' => '2026-10-29', 'metrics' => ['totalUsers']];
            $pending = $service->query('fixture', 7, $definition, 'actor');
            self::assertSame('pending', $pending['state']);
            $other = $service->query('fixture', 7, $definition, 'other-actor');
            self::assertNotSame($pending['report_id'], $other['report_id']);
            $service->process();
            $ready = $service->query('fixture', 7, $definition, 'actor');
            self::assertSame('ready', $ready['state']);
            self::assertTrue($ready['result']['quality']['details']['subjectToThresholding']);
            self::assertSame('5', $ready['result']['response']['rows'][0]['metricValues'][0]['value']);
            self::assertSame(2, $provider->calls);
            $this->expectException(\InvalidArgumentException::class);
            $service->query('fixture', 8, $definition, 'actor');
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }
}
