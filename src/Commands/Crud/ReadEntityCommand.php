<?php

namespace Commands\Crud;

use Controllers\CrudController;
use Doctrine\ORM\Exception\NotSupported;
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
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The id of the entity record')
            ->addOption('filters', 'f', InputOption::VALUE_OPTIONAL, 'The fields which will be used to filter the data');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('id')) {
            $result = (new CrudController())(
                entity: $input->getOption('entity'),
                method: 'read',
                id: $input->getOption('id'),
            );
        } else {
            $result = (new CrudController())(
                entity: $input->getOption('entity'),
                method: 'list',
                data: $input->getOption('filters'),
            );
        }

        $output->writeln('<info>' . $result->getContent() . '</info>');
        return Command::SUCCESS;
    }
}
