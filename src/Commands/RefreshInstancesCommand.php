<?php

    namespace Commands;

    use Exception;
    use Services\InstanceGeneratorService;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Yaml\Yaml;

    class RefreshInstancesCommand extends Command
    {
        protected static string $defaultName = 'app:refresh-instances';

        protected function configure(): void
        {
            $defaultPort = getenv('STARTING_HOST_PORT') ?: 8081;
            $this
                ->setName('app:refresh-instances')
                ->setDescription('Regenerates config/instances.yaml based on business rules.')
                ->addOption('no-deps', null, InputOption::VALUE_NONE, 'Do not add dependency chains between instances')
                ->addOption('base-port', 'p', InputOption::VALUE_REQUIRED, 'Base port to start from', (string)$defaultPort);
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $useDeps = !$input->getOption('no-deps');
            $basePort = (int)$input->getOption('base-port');

            $generator = new InstanceGeneratorService();
            try {
                $instances = $generator->generate($useDeps, $basePort);

                $configPath = __DIR__.'/../../config/instances.yaml';
                $yamlData = [
                    'instances' => $instances
                ];

                file_put_contents($configPath, Yaml::dump($yamlData, 10, 2));

                $output->writeln("<info>✔ Instances configuration regenerated successfully with ".count($instances)." instances.</info>");
                $output->writeln("<info>✔ File updated: config/instances.yaml</info>");

                // ─── Phase 4: Auto-regenerate Docker Compose Manifest ──────────────────
                $output->writeln("<comment>➤ Regenerating Docker Compose manifest...</comment>");
                $deploymentName = getenv('DEPLOYMENT_NAME') ?: 'apis-hub';
                $buildScript = realpath(__DIR__.'/../../bin/build-deployment.php');
                if ($buildScript) {
                    $cmd = "php \"$buildScript\" \"$deploymentName\" 2>&1";
                    exec($cmd, $buildOutput, $resultCode);
                    if ($resultCode === 0) {
                        $output->writeln("<info>✔ docker-compose.yml updated successfully.</info>");
                    } else {
                        $output->writeln("<error>✘ Failed to update docker-compose.yml: ".implode("\n", $buildOutput)."</error>");
                    }
                } else {
                    $output->writeln("<error>✘ build-deployment.php not found.</error>");
                }

                return Command::SUCCESS;
            } catch (Exception $e) {
                $output->writeln("<error>✘ Error generating instances: ".$e->getMessage()."</error>");

                return Command::FAILURE;
            }
        }
    }
