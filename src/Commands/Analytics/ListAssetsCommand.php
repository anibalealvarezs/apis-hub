<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Entities\Analytics\Channeled\ChanneledAccount;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:list-assets',
    description: 'Lists all connected accounts and assets across marketing channels',
    hidden: false
)]
class ListAssetsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Lists connected accounts, stores, properties, and ad accounts')
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'Filter by channel')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');
        $pretty = $input->getOption('pretty');

        $em = Helpers::getManager();
        $repo = $em->getRepository(ChanneledAccount::class);

        $criteria = ['enabled' => true];
        if ($channel) {
            $criteria['channel'] = $channel;
        }

        $accounts = $repo->findBy($criteria, ['channel' => 'ASC', 'name' => 'ASC']);

        $data = array_map(function (ChanneledAccount $acc) {
            return [
                'id' => $acc->getId(),
                'name' => $acc->getName(),
                'channel' => $acc->getChannel(),
                'platform_id' => $acc->getPlatformId(),
                'type' => $acc->getType(),
                'enabled' => $acc->isEnabled(),
            ];
        }, $accounts);

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $output->writeln(json_encode([
            'status' => 'success',
            'count' => count($data),
            'data' => $data,
        ], $flags));

        return Command::SUCCESS;
    }
}
