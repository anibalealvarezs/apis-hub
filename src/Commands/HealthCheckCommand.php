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
        $dbStatus = true;
        try {
            $em = Helpers::getManager();
            $em->getConnection()->connect();
            $output->writeln("<info>ONLINE</info>");
        } catch (Throwable $e) {
            $output->writeln("<error>OFFLINE: " . $e->getMessage() . "</error>");
            $dbStatus = false;
            $allPassed = false;
        }

        // 2. Redis Connection
        $output->write("🗄️  <comment>Redis Cache Server:</comment> ");
        $redisStatus = true;
        try {
            $redis = Helpers::getRedisClient();
            $redis->ping();
            $output->writeln("<info>ONLINE</info>");
        } catch (Throwable $e) {
            $output->writeln("<error>OFFLINE: " . $e->getMessage() . "</error>");
            $redisStatus = false;
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

        // 4. Catalog Entities (Countries/Devices/Accounts/Pages)
        $output->write("🌱 <comment>Catalog Integrity:</comment> ");
        $catalogStats = [];
        try {
            $db = Helpers::getManager()->getConnection();
            $catalogStats = [
                'countries' => (int)$db->fetchOne("SELECT COUNT(*) FROM countries"),
                'devices' => (int)$db->fetchOne("SELECT COUNT(*) FROM devices"),
                'accounts' => (int)$db->fetchOne("SELECT COUNT(*) FROM channeled_accounts"),
                'pages' => (int)$db->fetchOne("SELECT COUNT(*) FROM pages"),
                'campaigns' => (int)$db->fetchOne("SELECT COUNT(*) FROM campaigns"),
                'posts' => (int)$db->fetchOne("SELECT COUNT(*) FROM posts"),
                'queries' => (int)$db->fetchOne("SELECT COUNT(*) FROM queries")
            ];
            
            if ($catalogStats['countries'] > 0 && $catalogStats['devices'] > 0) {
                $output->writeln("<info>INITIALIZED ({$catalogStats['countries']} countries, {$catalogStats['devices']} devices, {$catalogStats['accounts']} accounts, {$catalogStats['pages']} pages, {$catalogStats['campaigns']} campaigns, {$catalogStats['posts']} posts, {$catalogStats['queries']} queries)</info>");
            } else {
                $output->writeln("<error>EMPTY TABLES (Run app:initialize-entities)</error>");
                $allPassed = false;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>READ FAILED: " . $e->getMessage() . "</error>");
            $allPassed = false;
        }

        // 5. MCP Node.js Server
        $output->write("🧠 <comment>MCP Interface (Port 3000):</comment> ");
        $mcpStatus = true;
        try {
            $mcpPort = (int) (getenv('MCP_PORT') ?: 3000);
            $errno = 0;
            $errstr = '';
            $connection = @stream_socket_client("tcp://127.0.0.1:$mcpPort", $errno, $errstr, 2);
            if (is_resource($connection)) {
                $output->writeln("<info>ONLINE (SSE Mode)</info>");
                fclose($connection);
            } else {
                $output->writeln("<error>OFFLINE (Port $mcpPort inaccessible)</error>");
                $mcpStatus = false;
                $allPassed = false;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>CHECK FAILED: " . $e->getMessage() . "</error>");
            $mcpStatus = false;
            $allPassed = false;
        }

        $output->writeln("\n" . str_repeat("─", 40));

        $status = $allPassed ? 'online' : 'error';
        $errorCount = $allPassed ? 0 : 1; // Basic count, could be expanded

        // 6. Report to Facade (Optional)
        $facadeUrl = getenv('MONITOR_FACADE_URL') ?: ($_ENV['MONITOR_FACADE_URL'] ?? null);
        $token = getenv('MONITOR_TOKEN') ?: ($_ENV['MONITOR_TOKEN'] ?? null);

        if ($facadeUrl && $token) {
            $output->write("📡 <comment>Reporting to Facade ($facadeUrl):</comment> ");
            try {
                $statusReport = \Services\HealthService::getFullHealthReport();
                $statusReport['status_summary'] = $allPassed ? 'System fully operational' : 'Issues detected in diagnostic';
                $statusReport['system']['mcp'] = $mcpStatus;
                
                $payload = json_encode($statusReport);

                $ch = curl_init($facadeUrl . '/api/heartbeat');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'X-Monitoring-Token: ' . $token,
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);

                $response = curl_exec($ch);
                $info = curl_getinfo($ch);
                if ($response === false || $info['http_code'] !== 200) {
                    $output->write("<error>FAILED (" . curl_errno($ch) . "): " . curl_error($ch) . " HTTP " . $info['http_code'] . "</error>\n");
                } else {
                    $output->write("<info>PASSED</info>\n");
                }
                curl_close($ch);
            } catch (Throwable $e) {
                $output->write("<error>ERROR (" . $e->getMessage() . ")</error>\n");
            }
        }

        if ($allPassed) {
            $output->writeln("<info>✅ SYSTEM HEALTHY - Deployment ready.</info>");
            return Command::SUCCESS;
        } else {
            $output->writeln("<error>❌ ISSUES DETECTED - Please check the items above.</error>");
            return Command::FAILURE;
        }
    }
}
