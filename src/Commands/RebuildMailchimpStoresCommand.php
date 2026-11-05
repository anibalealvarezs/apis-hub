<?php

declare(strict_types=1);

namespace Commands;

use Anibalealvarezs\MailchimpHubDriver\Drivers\MailchimpDriver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Services\Aggregation\AgnosticPreAggregationEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:rebuild-mailchimp-stores', description: 'Rebuild dated Mailchimp store metrics from cached orders.')]
final class RebuildMailchimpStoresCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('start', null, InputOption::VALUE_REQUIRED, 'First order date (YYYY-MM-DD)');
        $this->addOption('end', null, InputOption::VALUE_REQUIRED, 'Last order date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = (string) $input->getOption('start');
        $end = (string) $input->getOption('end');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$first || !$last || $first->format('Y-m-d') !== $start || $last->format('Y-m-d') !== $end || $first > $last) {
            $output->writeln('<error>Provide valid --start and --end dates in chronological order.</error>');
            return Command::INVALID;
        }
        $rule = MailchimpDriver::getPreAggregationRules()['store_conversions'] ?? [];
        if (empty($rule['identity_dimensions']) || ($rule['account_platform_id_field'] ?? null) !== 'audience_id') {
            $output->writeln('<error>Install the Mailchimp driver with store identities and audience linkage before rebuilding.</error>');
            return Command::FAILURE;
        }
        $engine = new AgnosticPreAggregationEngine($this->manager->getConnection());
        for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $result = $engine->preAggregateDay(['store_conversions' => $rule], 'mailchimp', $day, 30);
            $output->writeln(sprintf('%s: evaluated %d cached orders, wrote %d store metric slices.',
                $day, $result['records_evaluated'], $result['metrics_emitted']));
        }
        $output->writeln('Completed. Orders without a source date, store currency, or linked audience require a Mailchimp resync.');
        return Command::SUCCESS;
    }
}
