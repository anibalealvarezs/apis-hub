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
use \Helpers\Helpers;

#[AsCommand(
    name: 'app:aggregate',
    description: 'Returns aggregated data for entities.',
    aliases: ['app:sum', 'app:avg'],
    hidden: false
)]
class AggregateEntityCommand extends Command
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
            ->setDescription('Aggregate entity records')
            ->setHelp('This command allows you to get aggregated data (SUM, AVG) from entities')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity to aggregate')
            ->addOption('channel', 'c', InputOption::VALUE_OPTIONAL, 'The channel for the entity')
            ->addOption('aggregations', 'a', InputOption::VALUE_REQUIRED, 'JSON map of aggregations. e.g. {"clicks": "SUM(metadata.clicks)"}')
            ->addOption('group-by', 'g', InputOption::VALUE_OPTIONAL, 'Comma separated list of fields to group by')
            ->addOption('filters', 'f', InputOption::VALUE_OPTIONAL, 'The fields which will be used to filter the data (JSON body)')
            ->addOption('params', 'p', InputOption::VALUE_OPTIONAL, 'Additional query parameters (JSON or query string)')
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
        $aggregations = $input->getOption('aggregations');
        $groupBy = $input->getOption('group-by');
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

        // Add aggregations and groupBy to params if not already set
        if (!isset($params['aggregations'])) {
            $params['aggregations'] = json_decode($aggregations, true) ?: $aggregations;
        }
        if (!isset($params['groupBy']) && $groupBy) {
            $params['groupBy'] = explode(',', $groupBy);
        }
        if (!isset($params['startDate']) && $input->getOption('start-date')) {
            $params['startDate'] = $input->getOption('start-date');
        }
        if (!isset($params['endDate']) && $input->getOption('end-date')) {
            $params['endDate'] = $input->getOption('end-date');
        }

        if ($channel) {
            $controller = $this->channeledCrudController ?? new \Controllers\ChanneledCrudController();
            $result = $controller(
                entity: $entity,
                channel: $channel,
                method: 'aggregate',
                body: $body,
                params: $params
            );
        } else {
            $controller = $this->crudController ?? new CrudController();
            $result = $controller(
                entity: $entity,
                method: 'aggregate',
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
            
            fwrite(STDOUT, $responseContent . PHP_EOL);
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . $responseContent . '</error>');
        return Command::FAILURE;
    }
}
