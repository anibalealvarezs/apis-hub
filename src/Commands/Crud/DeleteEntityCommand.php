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

#[AsCommand(
    name: 'app:delete',
    description: 'Deletes and entity record.',
    aliases: ['app:remove'],
    hidden: false
)]
class DeleteEntityCommand extends Command
{
    private ?CrudController $crudController;
    
    public function __construct(?CrudController $crudController = null)
    {
        parent::__construct();
        $this->crudController = $crudController;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Delete an entity record')
            ->setHelp('This command allows you to get delete an entity record')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity record to be deleted')
            ->addOption('id', 'i', InputOption::VALUE_OPTIONAL, 'The id of the entity record')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty print the JSON response');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pretty = $input->getOption('pretty');
        $controller = $this->crudController ?? new CrudController();
        $result = ($controller)(
            entity: $input->getOption('entity'),
            method: 'delete',
            id: $input->getOption('id'),
        );

        $content = $result->getContent();
        if ($pretty) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $output->writeln('<info>' . $content . '</info>');
        return Command::SUCCESS;
    }
}
