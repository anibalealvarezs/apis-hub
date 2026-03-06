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
use Helpers\Helpers;

#[AsCommand(
    name: 'app:read',
    description: 'Gets entities records.',
    aliases: ['app:get'],
    hidden: false
)]
class ReadEntityCommand extends Command
{
    private ?CrudController $crudController;
    private ?\Controllers\ChanneledCrudController $channeledCrudController;

    public function __construct(
        ?CrudController $crudController = null,
        ?\Controllers\ChanneledCrudController $channeledCrudController = null
    ) {
        parent::__construct();
        $this->crudController = $crudController;
        $this->channeledCrudController = $channeledCrudController;
    }

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
            ->addOption('params', 'p', InputOption::VALUE_OPTIONAL, 'The query parameters like limit, pagination, hideFields (JSON or query string)')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty print the JSON response');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported|ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cliConfig = Helpers::getCliConfig();
        ini_set('memory_limit', $cliConfig['memory_limit'] ?? '1G');
        $entity = $input->getOption('entity');
        $channel = $input->getOption('channel');
        $id = $input->getOption('id');
        $body = $input->getOption('filters');
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

        if ($channel) {
            $controller = $this->channeledCrudController ?? new \Controllers\ChanneledCrudController();
            $result = $controller(
                entity: $entity,
                channel: $channel,
                method: $id ? 'read' : 'list',
                id: $id,
                body: $body,
                params: $params
            );
        } else {
            $controller = $this->crudController ?? new CrudController();
            $result = $controller(
                entity: $entity,
                method: $id ? 'read' : 'list',
                id: $id,
                body: $body,
                params: $params
            );
        }

        $responseContent = $result->getContent();
        if ($result->getStatusCode() >= 200 && $result->getStatusCode() < 300) {
            if ($pretty) {
                $content = json_decode($responseContent, true);
                if (is_array($content)) {
                    $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
                    $responseContent = json_encode($content, $jsonOptions);
                }
            }
            // Use raw output to bypass Symfony Console Formatter (prevents memory issues) 
            // while still allowing the CommandTester to capture output for tests.
            $output->write($responseContent . PHP_EOL, false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . $responseContent . '</error>');
        return Command::FAILURE;
    }
}
