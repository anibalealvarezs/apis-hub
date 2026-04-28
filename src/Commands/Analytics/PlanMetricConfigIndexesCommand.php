<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Services\MetricProfileIndexPlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:metric-profiles:plan-indexes',
    description: 'Builds MetricConfig index candidates from driver metric profiles'
)]
class PlanMetricConfigIndexesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Limit the plan to a specific channel slug')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the full plan as JSON')
            ->addOption('only-new', null, InputOption::VALUE_NONE, 'Show only candidates that are not exact/prefix-covered by existing indexes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $planner = new MetricProfileIndexPlanner();
        $plan = $planner->plan($input->getOption('channel'));

        if (($plan['channels'] ?? []) === []) {
            $output->writeln('<comment>No metric profiles were found for the selected channel scope.</comment>');
            return Command::SUCCESS;
        }

        if ((bool)$input->getOption('json')) {
            $output->writeln(json_encode($this->filterPlan($plan, (bool)$input->getOption('only-new')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $onlyNew = (bool)$input->getOption('only-new');
        $output->writeln('<info>Metric profile index planning</info>');
        $output->writeln('');

        foreach ($plan['channels'] as $channel => $channelPlan) {
            $output->writeln(sprintf('<comment>[%s]</comment>', $channel));

            foreach ($channelPlan['profiles'] as $profile) {
                $output->writeln(sprintf('  • %s (%s)', $profile['label'], $profile['key']));
                foreach ($profile['planned_indexes'] as $candidate) {
                    if ($onlyNew && in_array($candidate['status'], ['exact_match', 'covered_by_existing_left_prefix'], true)) {
                        continue;
                    }

                    $output->writeln(sprintf(
                        '    - %s => [%s] %s',
                        $candidate['candidate_name'],
                        implode(', ', $candidate['columns']),
                        $candidate['status']
                    ));

                    if ($candidate['matching_index']) {
                        $output->writeln(sprintf('      existing: %s', $candidate['matching_index']));
                    }
                    if ($candidate['unmapped_fields'] !== []) {
                        $output->writeln(sprintf('      unmapped: %s', implode(', ', $candidate['unmapped_fields'])));
                    }
                }
            }

            $output->writeln('');
        }

        $output->writeln('<info>Deduplicated candidates</info>');
        foreach ($plan['deduplicated_candidates'] as $candidate) {
            if ($onlyNew && in_array($candidate['status'], ['exact_match', 'covered_by_existing_left_prefix'], true)) {
                continue;
            }

            $output->writeln(sprintf(
                '  - [%s] %s | sources: %s',
                implode(', ', $candidate['columns']),
                $candidate['status'],
                implode(', ', $candidate['source_profiles'])
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function filterPlan(array $plan, bool $onlyNew): array
    {
        if (!$onlyNew) {
            return $plan;
        }

        foreach ($plan['channels'] as &$channelPlan) {
            foreach ($channelPlan['profiles'] as &$profile) {
                $profile['planned_indexes'] = array_values(array_filter(
                    $profile['planned_indexes'],
                    static fn (array $candidate): bool => !in_array($candidate['status'], ['exact_match', 'covered_by_existing_left_prefix'], true)
                ));
            }
            unset($profile);
        }
        unset($channelPlan);

        $plan['deduplicated_candidates'] = array_values(array_filter(
            $plan['deduplicated_candidates'],
            static fn (array $candidate): bool => !in_array($candidate['status'], ['exact_match', 'covered_by_existing_left_prefix'], true)
        ));

        return $plan;
    }
}

