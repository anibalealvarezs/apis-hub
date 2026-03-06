<?php

namespace Commands;

use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:health-check',
    description: 'Performs a comprehensive diagnostic of the infrastructure (DB, Redis, Schema)'
)]
class HealthCheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $allPassed = true;
        $output->writeln("🏥 <info>Starting apis-hub Diagnostic Dashboard</info>\n");

        // 1. Database Connection
        $output->write("📡 <comment>Database Connectivity:</comment> ");
        try {
            $em = Helpers::getManager();
            $em->getConnection()->connect();
            $output->writeln("<info>ONLINE</info>");
        } catch (Throwable $e) {
            $output->writeln("<error>OFFLINE: " . $e->getMessage() . "</error>");
            $allPassed = false;
        }

        // 2. Redis Connection
        $output->write("🗄️  <comment>Redis Cache Server:</comment> ");
        try {
            $redis = Helpers::getRedisClient();
            $redis->ping();
            $output->writeln("<info>ONLINE</info>");
        } catch (Throwable $e) {
            $output->writeln("<error>OFFLINE: " . $e->getMessage() . "</error>");
            $allPassed = false;
        }

        // 3. Schema Sync
        $output->write("📐 <comment>Doctrine Schema Sync:</comment> ");
        try {
            $em = Helpers::getManager();
            $tool = new \Doctrine\ORM\Tools\SchemaValidator($em);
            $mappingErrors = $tool->validateMapping();
            
            // Check for structural changes
            $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
            $allMetadata = $em->getMetadataFactory()->getAllMetadata();
            $sqls = $schemaTool->getUpdateSchemaSql($allMetadata, false);

            // Filter out "Phantom Diffs" (known Doctrine quirks)
            $filteredSqls = array_filter($sqls, function($sql) {
                // Ignore the recurring "ALTER TABLE queries ... COLLATE `utf8mb4_bin`"
                // which stays in the diff due to provider/comparator string mismatch
                if (stripos($sql, 'ALTER TABLE queries') !== false && stripos($sql, 'utf8mb4_bin') !== false) {
                    return false;
                }
                return true;
            });

            $schemaSynced = empty($filteredSqls);

            if (empty($mappingErrors) && $schemaSynced) {
                $output->write("<info>SYNCHRONIZED</info>");
                if (count($sqls) !== count($filteredSqls)) {
                    $output->writeln(" <comment>(with documented phantom diffs ignored)</comment>");
                } else {
                    $output->writeln("");
                }
            } else {
                $output->writeln("<error>OUT OF SYNC</error>");
                if (!empty($mappingErrors)) {
                    foreach ($mappingErrors as $class => $errors) {
                        $output->writeln("   - $class: " . implode(', ', $errors));
                    }
                }
                if (!$schemaSynced) {
                    $output->writeln("   - Database schema has pending structural changes:");
                    foreach ($filteredSqls as $sql) {
                        $output->writeln("     > $sql");
                    }
                }
                $allPassed = false;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>CHECK FAILED: " . $e->getMessage() . "</error>");
            $allPassed = false;
        }

        // 4. Catalog Entities (Countries/Devices)
        $output->write("🌱 <comment>Catalog Integrity:</comment> ");
        try {
            $db = Helpers::getManager()->getConnection();
            $countries = $db->fetchOne("SELECT COUNT(*) FROM countries");
            $devices = $db->fetchOne("SELECT COUNT(*) FROM devices");
            
            if ($countries > 0 && $devices > 0) {
                $output->writeln("<info>INITIALIZED ($countries countries, $devices devices)</info>");
            } else {
                $output->writeln("<error>EMPTY TABLES (Run app:initialize-entities)</error>");
                $allPassed = false;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>READ FAILED: " . $e->getMessage() . "</error>");
            $allPassed = false;
        }

        $output->writeln("\n" . str_repeat("─", 40));
        if ($allPassed) {
            $output->writeln("<info>✅ SYSTEM HEALTHY - Deployment ready.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>❌ ISSUES DETECTED - Please check the items above.</error>");
            return Command::FAILURE;
        }
    }
}
