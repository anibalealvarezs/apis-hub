<?php

namespace Commands;

use Classes\Crud;
use Doctrine\ORM\Exception\NotSupported;
use Enums\CrudMethods;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:create',
    description: 'Creates entity record.',
    aliases: ['app:new'],
    hidden: false
)]
class CreateEntityCommand extends Command
{
    /**
     * @var CrudMethods
     */
    protected CrudMethods $method = CrudMethods::create;

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Create entity record')
            ->setHelp('This command allows you to get create a new entity record')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity which the record will be created in')
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'The data which will be used to create the record');
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
            data: $input->getOption('data'),
        );

        if ($result) {
            $output->writeln('<info>' . $result . '</info>');
            return Command::SUCCESS;
        } else {
            $output->writeln('<error>' . $result . '</error>');
            return Command::FAILURE;
        }
    }
}
