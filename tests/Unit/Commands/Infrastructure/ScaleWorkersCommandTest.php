<?php

declare(strict_types=1);

namespace Tests\Unit\Commands\Infrastructure;

use Commands\Infrastructure\ScaleWorkersCommand;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Entities\Job;
use Enums\JobStatus;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ScaleWorkersCommandTest extends TestCase
{
    public function testDelayedOnlyQueueStillDemandsWorkers(): void
    {
        $job = new Job();
        $job->addChannel('google_search_console');
        $job->addPayload(['instance_name' => 'gsc-historical']);
        $jobs = $this->createMock(EntityRepository::class);
        $jobs->method('findBy')->willReturnCallback(static function (array $criteria) use ($job): array {
            return in_array(JobStatus::delayed->value, (array)$criteria['status'], true) ? [$job] : [];
        });
        $channels = $this->createMock(EntityRepository::class);
        $channels->method('findAll')->willReturn([]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRepository')->willReturnCallback(static fn ($class) => $class === Job::class ? $jobs : $channels);

        // Stop at the calculated demand, before Docker inspection or scaling.
        $stop = new RuntimeException('Demand inspected');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->willReturnCallback(function ($message) use ($stop): void {
            self::assertMatchesRegularExpression('/Target Workers: [1-9][0-9]* /', $message);
            throw $stop;
        });
        try {
            (new ReflectionMethod(ScaleWorkersCommand::class, 'execute'))->invoke(
                new ScaleWorkersCommand($em), $this->createMock(InputInterface::class), $output
            );
            self::fail('Expected to stop before Docker operations');
        } catch (RuntimeException $error) {
            self::assertSame($stop, $error);
        }
    }
}

