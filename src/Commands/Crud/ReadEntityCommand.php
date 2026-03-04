<?php

namespace Commands\Crud;

use Controllers\CrudController;
use Doctrine\ORM\Exception\NotSupported;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:read',
    description: 'Gets entities records.',
    aliases: ['app:get'],
    hidden: false
)]
class ReadEntityCommand extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Read entity records')
            ->setHelp('This command allows you to get entities data')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity which the data will be retrieved from')
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'The channel for the entity (e.g., google_search_console)')
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The id of the entity record')
            ->addOption('filters', 'f', InputOption::VALUE_OPTIONAL, 'The fields which will be used to filter the data (JSON body)')
            ->addOption('params', 'p', InputOption::VALUE_OPTIONAL, 'The query parameters like limit, pagination, hideFields (JSON or query string)');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported|ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entity = $input->getOption('entity');
        $channel = $input->getOption('channel');
        $id = $input->getOption('id');
        $body = $input->getOption('filters');
        $paramsString = $input->getOption('params');

        $params = [];
        if ($paramsString) {
            $paramsArray = json_decode($paramsString, true);
            if (is_array($paramsArray)) {
                $params = $paramsArray;
            } else {
                parse_str($paramsString, $params);
            }
        }

        if ($channel) {
            $controller = new \Controllers\ChanneledCrudController();
            $result = $controller(
                entity: $entity,
                channel: $channel,
                method: $id ? 'read' : 'list',
                id: $id,
                body: $body,
                params: $params
            );
        } else {
            $controller = new CrudController();
            $result = $controller(
                entity: $entity,
                method: $id ? 'read' : 'list',
                id: $id,
                body: $body,
                params: $params
            );
        }

        if ($result->getStatusCode() >= 200 && $result->getStatusCode() < 300) {
            $content = json_decode($result->getContent(), true) ?? $result->getContent();
            $output->writeln('<info>' . (is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $content) . '</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . $result->getContent() . '</error>');
        return Command::FAILURE;
    }
}
