<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Controllers\CatalogController;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

#[AsCommand(
    name: 'app:aggregate-catalog',
    description: 'Executes Two-Stage dynamic Entity Measures aggregation over cached catalog entities',
    hidden: false
)]
class AggregateCatalogEntitiesCommand extends Command
{
    private ?CatalogController $catalogController;

    public function __construct(?CatalogController $catalogController = null)
    {
        parent::__construct();
        $this->catalogController = $catalogController;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Aggregate catalog entities (orders, customers, stores, products, discounts, campaigns, ad-groups, ads, posts, events)')
            ->addOption('payload', 'p', InputOption::VALUE_OPTIONAL, 'JSON payload for CatalogController::aggregate')
            ->addOption('capabilities', null, InputOption::VALUE_NONE, 'Return EntityMeasureCatalog capabilities instead of running an aggregation')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cliConfig = Helpers::getCliConfig();
        ini_set('memory_limit', $cliConfig['memory_limit'] ?? '1G');

        $pretty = (bool) $input->getOption('pretty');
        $capabilitiesOnly = (bool) $input->getOption('capabilities');
        $rawPayload = (string) ($input->getOption('payload') ?? '{}');

        $controller = $this->catalogController ?? new CatalogController(Helpers::getManager());

        if ($capabilitiesOnly) {
            $request = Request::create('/api/catalog/aggregate/capabilities', 'GET');
            $response = $controller->aggregateCapabilities($request);
        } else {
            $request = Request::create(
                '/api/catalog/aggregate',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $rawPayload !== '' ? $rawPayload : '{}'
            );
            $response = $controller->aggregate($request);
        }

        $content = (string) $response->getContent();
        if ($pretty) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $content = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }

        $output->writeln($content);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
