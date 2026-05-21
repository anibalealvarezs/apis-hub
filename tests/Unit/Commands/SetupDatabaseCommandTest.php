<?php

    namespace Tests\Unit\Commands;

    use Commands\SetupDatabaseCommand;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Console\Application;
    use Symfony\Component\Console\Command\Command;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Tester\CommandTester;

    class SetupDatabaseCommandTest extends TestCase
    {
        private string $originalDbDriver;
        private string $originalDbName;
        private string $originalAppEnv;

        protected function setUp(): void
        {
            $this->originalDbDriver = getenv('DB_DRIVER') ?: '';
            $this->originalDbName = getenv('DB_NAME') ?: '';
            $this->originalAppEnv = getenv('APP_ENV') ?: '';

            // Force PostgreSQL mode to skip real MySQL socket connection attempts in test
            putenv('DB_DRIVER=pdo_pgsql');
            putenv('DB_NAME=apis-hub-test');
        }

        protected function tearDown(): void
        {
            putenv("DB_DRIVER=".$this->originalDbDriver);
            putenv("DB_NAME=".$this->originalDbName);
            putenv("APP_ENV=".$this->originalAppEnv);
        }

        public function testSetupDatabaseSuccessFlow()
        {
            putenv('APP_ENV=demo'); // Triggers seed demo data command run

            $application = new Application();

            // 1. Stub 'orm:schema-tool:update'
            $schemaMock = new SetupDatabaseTestCommandHelper('orm:schema-tool:update', Command::SUCCESS);
            $application->add($schemaMock);

            // 2. Stub 'app:install-drivers'
            $installDriversMock = new SetupDatabaseTestCommandHelper('app:install-drivers', Command::SUCCESS);
            $application->add($installDriversMock);

            // 3. Stub 'app:initialize-entities'
            $initEntitiesMock = new SetupDatabaseTestCommandHelper('app:initialize-entities', Command::SUCCESS);
            $application->add($initEntitiesMock);

            // 4. Stub 'app:seed-demo-data'
            $seedDemoMock = new SetupDatabaseTestCommandHelper('app:seed-demo-data', Command::SUCCESS);
            $application->add($seedDemoMock);

            $command = new SetupDatabaseCommand();
            $application->add($command);

            $commandTester = new CommandTester($command);
            $commandTester->execute([]);

            $output = $commandTester->getDisplay();
            $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
            $this->assertStringContainsString("Ensuring database 'apis-hub-test' exists...", $output);
            $this->assertStringContainsString("Notice: Assuming database 'apis-hub-test' is already created by Docker/Postgres environment.", $output);
            $this->assertStringContainsString("Updating schema structure...", $output);
            $this->assertStringContainsString("Registration of Providers and Channels...", $output);
            $this->assertStringContainsString("Seeding initial entities...", $output);
            $this->assertStringContainsString("Environment is 'demo'. Filling with sample data...", $output);
            $this->assertStringContainsString("Database setup complete!", $output);

            $this->assertEquals(1, $schemaMock->getCalls());
            $this->assertEquals(1, $installDriversMock->getCalls());
            $this->assertEquals(1, $initEntitiesMock->getCalls());
            $this->assertEquals(1, $seedDemoMock->getCalls());
        }

        public function testSetupDatabaseHandlesExceptionsGracefully()
        {
            putenv('APP_ENV=testing'); // Skips seed demo command to isolate exception handling

            $application = new Application();

            // 1. Stub 'orm:schema-tool:update'
            $schemaMock = new SetupDatabaseTestCommandHelper('orm:schema-tool:update', Command::SUCCESS);
            $application->add($schemaMock);

            // 2. Stub 'app:install-drivers' (Which returns failure status code)
            $installDriversMock = new SetupDatabaseTestCommandHelper('app:install-drivers', Command::FAILURE);
            $application->add($installDriversMock);

            $command = new SetupDatabaseCommand();
            $application->add($command);

            $commandTester = new CommandTester($command);
            $commandTester->execute([]);

            $output = $commandTester->getDisplay();
            $this->assertEquals(Command::FAILURE, $commandTester->getStatusCode());
            $this->assertStringContainsString("Error during database setup: Failed to install/register drivers.", $output);

            $this->assertEquals(1, $schemaMock->getCalls());
            $this->assertEquals(1, $installDriversMock->getCalls());
        }
    }

    class SetupDatabaseTestCommandHelper extends Command
    {
        private int $result;
        private int $calls = 0;

        public function __construct(string $name, int $result = Command::SUCCESS)
        {
            parent::__construct($name);
            $this->result = $result;
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $this->calls++;

            return $this->result;
        }

        public function getCalls(): int
        {
            return $this->calls;
        }
    }
