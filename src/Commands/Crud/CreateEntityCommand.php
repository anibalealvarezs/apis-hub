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
    name: 'app:create',
    description: 'Creates entity record.',
    aliases: ['app:new'],
    hidden: false
)]
class CreateEntityCommand extends Command
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
            ->setDescription('Create entity record')
            ->setHelp('This command allows you to get create a new entity record')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'The entity which the record will be created in')
            ->addOption('data', 'd', InputOption::VALUE_OPTIONAL, 'The data which will be used to create the record')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty print the JSON response');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws NotSupported|ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pretty = $input->getOption('pretty');
        $controller = $this->crudController ?? new CrudController();
        $result = ($controller)(
            entity: $input->getOption('entity'),
            method: 'create',
            body: $input->getOption('data'),
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
