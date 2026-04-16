<?php

namespace Commands;

use Helpers\Helpers;
use Services\InstanceGeneratorService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class RefreshInstancesCommand extends Command
{
    protected static $defaultName = 'app:refresh-instances';

    protected function configure()
    {
        $defaultPort = getenv('STARTING_HOST_PORT') ?: 8081;
        $this
            ->setDescription('Regenerates config/instances.yaml based on business rules.')
            ->addOption(name: 'no-deps', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Do not add dependency chains between instances')
            ->addOption(name: 'base-port', shortcut: 'p', mode: InputOption::VALUE_REQUIRED, description: 'Base port to start from', default: (string) $defaultPort);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $useDeps = !$input->getOption('no-deps');
        $basePort = (int) $input->getOption('base-port');

        $generator = new InstanceGeneratorService();
        try {
            $instances = $generator->generate($useDeps, $basePort);
            
            $configPath = __DIR__ . '/../../config/instances.yaml';
            $yamlData = [
                'instances' => $instances
            ];

            file_put_contents($configPath, Yaml::dump($yamlData, 10, 2));

            $output->writeln("<info>✔ Instances configuration regenerated successfully with " . count($instances) . " instances.</info>");
            $output->writeln("<info>✔ File updated: config/instances.yaml</info>");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>✘ Error generating instances: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
