<?php

namespace Commands\Crud;

use Classes\Crud;
use Doctrine\ORM\Exception\NotSupported;
use Enums\CrudMethods;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:update',
    description: 'Updates an entity record.',
    aliases: ['app:edit'],
    hidden: false
)]
class UpdateEntityCommand extends Command
{
    /**
     * @var CrudMethods
     */
    protected CrudMethods $method = CrudMethods::update;

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Update an entity record')
            ->setHelp('This command allows you to get update an entity record')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity which the record will be updated in')
            ->addOption('id', 'i', InputOption::VALUE_REQUIRED, 'The id of the entity record')
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'The data which will be used to update the record');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new Crud())(
            entity: $input->getOption('entity'),
            method: $this->method->getName(),
            id: $input->getOption('id'),
            data: $input->getOption('data'),
            cli: true,
        );

        $output->writeln('<info>' . $result . '</info>');
        return Command::SUCCESS;
    }
}
