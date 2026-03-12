<?php

declare(strict_types=1);

namespace Commands;

use Classes\Requests\AdGroupRequests;
use Classes\Requests\AdRequests;
use Classes\Requests\CampaignRequests;
use Classes\Requests\PageRequests;
use Classes\Requests\PostRequests;
use Helpers\Helpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cache-facebook-entities',
    description: 'Syncs Facebook entities (Campaigns, AdGroups, Ads, Posts) to database'
)]
class CacheFacebookEntitiesCommand extends Command
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = Helpers::setLogger('facebook-cache.log');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('jobId', null, InputOption::VALUE_OPTIONAL, 'The ID of the job associating this execution');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = $input->getOption('jobId') ? (int) $input->getOption('jobId') : null;
        
        if (Helpers::isDebug()) {
            $output->writeln('<info>Starting Facebook entities cache sync...</info>');
        }
        $this->logger->info('Starting Facebook entities cache sync');

        try {
            // 1. Sync Pages
            if (Helpers::isDebug()) $output->writeln('Syncing Pages...');
            PageRequests::getListFromFacebook($this->logger, $jobId);

            // 2. Sync Campaigns
            if (Helpers::isDebug()) $output->writeln('Syncing Campaigns...');
            CampaignRequests::getListFromFacebook($this->logger, $jobId);

            // 3. Sync AdGroups (AdSets)
            if (Helpers::isDebug()) $output->writeln('Syncing AdGroups...');
            AdGroupRequests::getListFromFacebook($this->logger, $jobId);

            // 4. Sync Ads
            if (Helpers::isDebug()) $output->writeln('Syncing Ads...');
            AdRequests::getListFromFacebook($this->logger, $jobId);

            // 5. Sync Posts (Facebook & Instagram Media)
            if (Helpers::isDebug()) $output->writeln('Syncing Posts...');
            PostRequests::getListFromFacebook($this->logger, $jobId);

            if (Helpers::isDebug()) {
                $output->writeln('<info>Facebook entities cache sync completed successfully</info>');
            }
            $this->logger->info('Facebook entities cache sync completed successfully');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->error('Error in CacheFacebookEntitiesCommand: ' . $e->getMessage());
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
