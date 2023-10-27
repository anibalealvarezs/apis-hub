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
    name: 'app:read',
    description: 'Gets entities records.',
    aliases: ['app:get'],
    hidden: false
)]
class ReadEntityCommand extends Command
{
    /**
     * @var CrudMethods
     */
    protected CrudMethods $method = CrudMethods::read;

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
        $result = (new Crud())(
            entity: $input->getOption('entity'),
            method: $this->method->getName(),
            id: $input->getOption('id'),
            data: $input->getOption('filters'),
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
