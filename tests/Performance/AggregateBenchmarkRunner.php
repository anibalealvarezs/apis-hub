<?php

declare(strict_types=1);

namespace Tests\Performance;

use Helpers\Helpers;
use RuntimeException;

final class AggregateBenchmarkRunner
{
    /**
     * @var array<string, string>
     */
    private const ENTITY_ALIASES = [
        'metric' => 'Entities\\Analytics\\Metric',
        'channeled_metric' => 'Entities\\Analytics\\Channeled\\ChanneledMetric',
    ];

    public static function main(array $argv): int
    {
        $options = self::parseOptions($argv);

        if (($options['help'] ?? false) === true) {
            self::printHelp();
            return 0;
        }

        $payloadFiles = self::resolvePayloadFiles($options);
        if ($payloadFiles === []) {
            file_put_contents('php://stderr', "No payload files found. Use --payload=path.json or --payload-dir=path\\to\\dir.\n");
            return 1;
        }

        $entityClass = self::resolveEntityClass((string)($options['entity'] ?? 'channeled_metric'));
        $runs = max(1, (int)($options['runs'] ?? 3));
        $warmupRuns = max(0, (int)($options['warmup'] ?? 1));
        $debugSql = ($options['debug-sql'] ?? false) === true;
        $dryRun = ($options['dry-run'] ?? false) === true;
        $output = strtolower((string)($options['output'] ?? 'table'));

        $payloads = [];
        foreach ($payloadFiles as $payloadFile) {
            $payloads[] = [
                'file' => $payloadFile,
                'payload' => self::loadPayload($payloadFile, $debugSql),
            ];
        }

        if ($dryRun) {
            self::printDryRun($entityClass, $payloads, $runs, $warmupRuns, $debugSql);
            return 0;
        }

        $repository = Helpers::getManager()->getRepository($entityClass);
        $results = [];

        foreach ($payloads as $entry) {
            $payload = $entry['payload'];

            for ($i = 0; $i < $warmupRuns; $i++) {
                $repository->aggregate(
                    aggregations: (array)($payload['aggregations'] ?? []),
                    groupBy: (array)($payload['groupBy'] ?? []),
                    filters: self::toObject($payload['filters'] ?? []),
                    startDate: $payload['startDate'] ?? null,
                    endDate: $payload['endDate'] ?? null,
                    orderBy: $payload['orderBy'] ?? null,
                    orderDir: $payload['orderDir'] ?? 'ASC',
                );
            }

            $timings = [];
            $rowCount = 0;
            for ($i = 0; $i < $runs; $i++) {
                $startedAt = microtime(true);
                $rows = $repository->aggregate(
                    aggregations: (array)($payload['aggregations'] ?? []),
                    groupBy: (array)($payload['groupBy'] ?? []),
                    filters: self::toObject($payload['filters'] ?? []),
                    startDate: $payload['startDate'] ?? null,
                    endDate: $payload['endDate'] ?? null,
                    orderBy: $payload['orderBy'] ?? null,
                    orderDir: $payload['orderDir'] ?? 'ASC',
                );
                $timings[] = (microtime(true) - $startedAt) * 1000;
                $rowCount = count($rows);
            }

            $results[] = [
                'name' => pathinfo($entry['file'], PATHINFO_FILENAME),
                'file' => $entry['file'],
                'entity' => $entityClass,
                'groupBy' => $payload['groupBy'] ?? [],
                'rowCount' => $rowCount,
                'runs' => $runs,
                'warmupRuns' => $warmupRuns,
                'minMs' => min($timings),
                'avgMs' => array_sum($timings) / count($timings),
                'maxMs' => max($timings),
                'timingsMs' => array_map(static fn (float $value): float => round($value, 3), $timings),
            ];
        }

        if ($output === 'json') {
            echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        self::printTable($results);
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseOptions(array $argv): array
    {
        $options = [];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($arg === '--debug-sql') {
                $options['debug-sql'] = true;
                continue;
            }
            if ($arg === '--dry-run') {
                $options['dry-run'] = true;
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
            if ($value === null) {
                $options[$key] = true;
                continue;
            }

            if ($key === 'payload') {
                $options['payload'][] = $value;
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private static function resolvePayloadFiles(array $options): array
    {
        $files = [];

        foreach ((array)($options['payload'] ?? []) as $file) {
            $real = realpath((string)$file);
            if ($real !== false && is_file($real)) {
                $files[] = $real;
            }
        }

        if (!empty($options['payload-dir'])) {
            $dir = realpath((string)$options['payload-dir']);
            if ($dir !== false && is_dir($dir)) {
                $dirFiles = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
                sort($dirFiles);
                foreach ($dirFiles as $file) {
                    $real = realpath($file);
                    if ($real !== false) {
                        $files[] = $real;
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadPayload(string $filePath, bool $debugSql): array
    {
        $decoded = json_decode((string)file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Payload file must decode to an object: {$filePath}");
        }

        $decoded['filters'] = is_array($decoded['filters'] ?? null) ? $decoded['filters'] : [];
        if ($debugSql) {
            $decoded['filters']['debug_sql'] = 1;
        }

        return $decoded;
    }

    private static function resolveEntityClass(string $entity): string
    {
        return self::ENTITY_ALIASES[strtolower($entity)] ?? $entity;
    }

    private static function toObject(array $data): ?object
    {
        if ($data === []) {
            return null;
        }

        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     */
    private static function printDryRun(string $entityClass, array $payloads, int $runs, int $warmupRuns, bool $debugSql): void
    {
        echo "Dry run OK\n";
        echo "Entity: {$entityClass}\n";
        echo "Runs: {$runs}\n";
        echo "Warmup: {$warmupRuns}\n";
        echo "Debug SQL: " . ($debugSql ? 'yes' : 'no') . "\n";
        echo "Payloads:\n";
        foreach ($payloads as $entry) {
            $groupBy = json_encode($entry['payload']['groupBy'] ?? [], JSON_UNESCAPED_SLASHES);
            echo " - {$entry['file']} | groupBy={$groupBy}\n";
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private static function printTable(array $results): void
    {
        $headers = ['name', 'rows', 'min_ms', 'avg_ms', 'max_ms', 'groupBy'];
        $widths = array_fill_keys($headers, 0);
        foreach ($headers as $header) {
            $widths[$header] = max($widths[$header], strlen($header));
        }

        $rows = [];
        foreach ($results as $result) {
            $row = [
                'name' => (string)$result['name'],
                'rows' => (string)$result['rowCount'],
                'min_ms' => number_format((float)$result['minMs'], 3, '.', ''),
                'avg_ms' => number_format((float)$result['avgMs'], 3, '.', ''),
                'max_ms' => number_format((float)$result['maxMs'], 3, '.', ''),
                'groupBy' => json_encode($result['groupBy'], JSON_UNESCAPED_SLASHES),
            ];
            $rows[] = $row;
            foreach ($row as $key => $value) {
                $widths[$key] = max($widths[$key], strlen((string)$value));
            }
        }

        $separator = '+';
        foreach ($headers as $header) {
            $separator .= str_repeat('-', $widths[$header] + 2) . '+';
        }

        echo $separator . PHP_EOL;
        echo '|';
        foreach ($headers as $header) {
            echo ' ' . str_pad($header, $widths[$header]) . ' |';
        }
        echo PHP_EOL . $separator . PHP_EOL;

        foreach ($rows as $row) {
            echo '|';
            foreach ($headers as $header) {
                echo ' ' . str_pad((string)$row[$header], $widths[$header]) . ' |';
            }
            echo PHP_EOL;
        }

        echo $separator . PHP_EOL;
    }

    private static function printHelp(): void
    {
        echo <<<'TXT'
Aggregate benchmark runner for APIs Hub.

Usage:
  php tests/Performance/aggregate-benchmark.php [options]

Options:
  --entity=alias|fqcn         Entity repository to benchmark. Aliases: metric, channeled_metric.
  --payload=path.json         Payload file to run. Can be repeated.
  --payload-dir=path\to\dir  Directory with payload JSON files.
  --runs=n                    Number of measured runs per payload. Default: 3.
  --warmup=n                  Warmup runs before measuring. Default: 1.
  --debug-sql                 Inject filters.debug_sql=1 into each payload.
  --dry-run                   Validate options/payloads without touching the database.
  --output=table|json         Output format. Default: table.
  --help                      Show this help.

Examples:
  php tests/Performance/aggregate-benchmark.php --dry-run --payload-dir=tests/Performance/fixtures
  php tests/Performance/aggregate-benchmark.php --entity=channeled_metric --payload-dir=tests/Performance/fixtures --runs=5 --warmup=1 --debug-sql
TXT;
        echo PHP_EOL;
    }
}

