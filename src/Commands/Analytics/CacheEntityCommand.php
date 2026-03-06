<?php

namespace Commands\Analytics;

use Controllers\CacheController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

#[AsCommand(
    name: 'apis-hub:cache',
    description: 'Schedules a caching job for an analytics entity.',
    aliases: ['app:cache'],
    hidden: false
)]
class CacheEntityCommand extends Command
{
    private ?CacheController $controller;

    public function __construct(?CacheController $controller = null)
    {
        $this->controller = $controller;
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Schedule a caching job for an analytics entity.')
            ->setHelp('This command allows you to schedule a caching job for a specific entity and channel, identical to the API endpoint logic.')
            ->addArgument('channel', InputArgument::REQUIRED, 'The channel to cache from (e.g. shopify, klaviyo)')
            ->addArgument('entity', InputArgument::REQUIRED, 'The entity to cache (e.g. products, customers)')
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'The JSON body data to pass to the request')
            ->addOption('params', 'p', InputOption::VALUE_OPTIONAL, 'The JSON or query string parameters to pass to the request')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty print the JSON response');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getArgument('channel');
        $entity = $input->getArgument('entity');
        $body = $input->getOption('data');
        $paramsString = $input->getOption('params');
        $pretty = $input->getOption('pretty');

        $params = [];
        if ($paramsString) {
            $paramsArray = json_decode($paramsString, true);
            if (is_array($paramsArray)) {
                $params = $paramsArray;
            } else {
                parse_str($paramsString, $params);
            }
        }

        $controller = $this->controller ?? new CacheController();
        /** @var Response $response */
        $response = $controller(
            channel: $channel,
            entity: $entity,
            body: $body,
            params: $params
        );

        $content = json_decode($response->getContent(), true) ?? $response->getContent();
        $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $jsonOptions |= JSON_PRETTY_PRINT;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $output->writeln('<info>Success (' . $response->getStatusCode() . '): ' . (is_array($content) ? json_encode($content, $jsonOptions) : $content) . '</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Error (' . $response->getStatusCode() . '): ' . (is_array($content) ? json_encode($content, $jsonOptions) : $content) . '</error>');
        return Command::FAILURE;
    }
}
