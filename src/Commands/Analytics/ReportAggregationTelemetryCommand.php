<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Services\Aggregation\AggregationFallbackTelemetryReporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:aggregation-telemetry-report',
    description: 'Summarizes aggregation fallback telemetry by pilot and non-pilot channels'
)]
final class ReportAggregationTelemetryCommand extends Command
{
    public function __construct(private readonly ?AggregationFallbackTelemetryReporter $reporter = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Path to a JSON file containing telemetry events or an object with events/pilot_channels')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Optional path to write the generated JSON summary')
            ->addOption('pilot-channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Pilot channel key to segment (repeat option for multiple channels)')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = $input->getOption('input');
        if (!is_string($inputPath) || trim($inputPath) === '') {
            $output->writeln('<error>Missing required --input path.</error>');
            return Command::FAILURE;
        }

        try {
            $payload = $this->readJsonPayload($inputPath);
            [$events, $pilotChannels] = $this->extractReportInputs($payload, $input->getOption('pilot-channel'));

            $reporter = $this->reporter ?? new AggregationFallbackTelemetryReporter();
            $summary = $reporter->summarize($events, $pilotChannels);

            $flags = JSON_UNESCAPED_SLASHES;
            if ($input->getOption('pretty') === true) {
                $flags |= JSON_PRETTY_PRINT;
            }

            $json = json_encode($summary, $flags | JSON_THROW_ON_ERROR);
            $output->writeln($json);

            $outputPath = $input->getOption('output');
            if (is_string($outputPath) && trim($outputPath) !== '') {
                file_put_contents($outputPath, $json . PHP_EOL);
                $output->writeln(sprintf('<info>Summary written to %s</info>', $outputPath));
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function readJsonPayload(string $inputPath): array
    {
        if (!is_file($inputPath)) {
            throw new \RuntimeException(sprintf('Input file not found: %s', $inputPath));
        }

        $raw = file_get_contents($inputPath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read input file: %s', $inputPath));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Telemetry input must decode to an array or object payload.');
            }

            return $decoded;
        } catch (\JsonException $jsonException) {
            $decodedNdjson = $this->decodeNdjsonPayload($raw);
            if ($decodedNdjson !== null) {
                return $decodedNdjson;
            }

            throw $jsonException;
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function decodeNdjsonPayload(string $raw): ?array
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $events = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            try {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }

            if (!is_array($decoded)) {
                return null;
            }

            $events[] = $decoded;
        }

        return $events === [] ? null : $events;
    }

    /**
     * @param array<int, mixed>|array<string, mixed> $payload
     * @param mixed $pilotChannelOption
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function extractReportInputs(array $payload, mixed $pilotChannelOption): array
    {
        $events = $this->isList($payload) ? $payload : ($payload['events'] ?? null);
        if (!is_array($events)) {
            throw new \RuntimeException('Telemetry payload must contain an events array.');
        }

        $normalizedEvents = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $normalizedEvents[] = $event;
            }
        }

        $pilotChannels = [];
        $payloadPilotChannels = $this->isList($payload) ? [] : ($payload['pilot_channels'] ?? []);
        foreach ([$payloadPilotChannels, $pilotChannelOption] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $channel) {
                if (!is_scalar($channel)) {
                    continue;
                }

                $normalized = strtolower(trim((string)$channel));
                if ($normalized !== '' && !in_array($normalized, $pilotChannels, true)) {
                    $pilotChannels[] = $normalized;
                }
            }
        }

        return [$normalizedEvents, $pilotChannels];
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

