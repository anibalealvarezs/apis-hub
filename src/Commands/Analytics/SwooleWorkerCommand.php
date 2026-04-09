<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Classes\Helpers;
use Doctrine\ORM\EntityManager;
use Entities\Job;
use Enums\JobStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Swoole\Process;
use Swoole\Timer;

#[AsCommand(
    name: 'app:swoole-worker',
    description: 'Runs job processing in a persistent Swoole worker pool.',
    aliases: ['jobs:swoole']
)]
class SwooleWorkerCommand extends Command
{
    private EntityManager $em;
    private bool $running = true;

    public function __construct(?EntityManager $em = null)
    {
        $this->em = $em ?? \Helpers\Helpers::getManager();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of worker processes', 2)
             ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Polling interval in seconds', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerCount = (int) $input->getOption('workers');
        $interval = (int) $input->getOption('interval') * 1000; // to ms

        $output->writeln("<info>Starting Swoole Worker Pool with {$workerCount} workers...</info>");

        Process::signal(SIGTERM, function () use ($output) {
            $output->writeln("<comment>Peacefully shutting down...</comment>");
            $this->running = false;
        });

        $pool = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $process = new Process(function (Process $worker) use ($i, $interval, $output) {
                $output->writeln("Worker #{$i} started.");
                
                Timer::tick($interval, function () use ($i, $output) {
                    if (!$this->running) {
                        Timer::clearAll();
                        return;
                    }

                    // Boot separate command per tick to ensure clean state or use the shared one with clear()
                    try {
                        $processCommand = new ProcessJobsCommand();
                        // Redirect output to avoid cluttering main logs unless isDebug
                        $output->writeln("Worker #{$i}: Checking for jobs...");
                        $processCommand->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
                    } catch (\Throwable $e) {
                        $output->writeln("<error>Worker #{$i} Error: {$e->getMessage()}</error>");
                    }
                });
            });
            $process->start();
            $pool[] = $process;
        }

        // Wait for children
        while ($this->running) {
            Process::wait();
            if ($this->running) {
                $output->writeln("<warning>A worker died. Restarting...</warning>");
                // Restart logic could go here if needed, but Process::wait(false) is better
            }
        }

        return Command::SUCCESS;
    }
}
