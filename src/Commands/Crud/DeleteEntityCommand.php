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
    name: 'app:delete',
    description: 'Deletes and entity record.',
    aliases: ['app:remove'],
    hidden: false
)]
class DeleteEntityCommand extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Delete an entity record')
            ->setHelp('This command allows you to get delete an entity record')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity record to be deleted')
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The id of the entity record');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new CrudController())(
            entity: $input->getOption('entity'),
            method: 'delete',
            id: $input->getOption('id'),
        );

        $output->writeln('<info>' . $result . '</info>');
        return Command::SUCCESS;
    }
}
