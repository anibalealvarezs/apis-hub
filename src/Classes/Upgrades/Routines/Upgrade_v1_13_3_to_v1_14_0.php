<?php

namespace Classes\Upgrades\Routines;

use Commands\RecalculateMetricConfigSignaturesCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_13_3_to_v1_14_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.13.3'];
    }

    public function getToVersion(): string
    {
        return '1.14.0';
    }

    public function getDescription(): string
    {
        return 'Adds geo location fields (location, state, city) and event entities (events, channeled_events) to metric_configs and recalculates signatures.';
    }

    public function requiresNuclearResync(): bool
    {
        // Signatures are recalculated safely, so we don't strictly require a full database wipe.
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $conn = $em->getConnection();
        
        // 1. Run the raw SQL migrations
        $sqlPaths = [
            __DIR__ . '/../../../../migrations/Version20260617000001_add_location_geo_entities.sql',
            __DIR__ . '/../../../../migrations/Version20260623000001_add_event_entities.sql'
        ];
        foreach ($sqlPaths as $sqlPath) {
            if (file_exists($sqlPath)) {
                $output->writeln("   <info>[1/3]</info> Executing raw SQL migration: " . basename($sqlPath) . "...");
                $sql = file_get_contents($sqlPath);
                if (!empty(trim($sql))) {
                    $conn->executeStatement($sql);
                }
            } else {
                $output->writeln("   <comment>[1/3]</comment> SQL migration file not found. Skipping: " . basename($sqlPath));
            }
        }

        // 2. Sync schema using Doctrine (orm:schema-tool:update)
        $output->writeln("   <info>[2/3]</info> Synchronizing Doctrine schema...");
        $tool = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $tool->updateSchema($classes);

        // 3. Recalculate metric config signatures
        $output->writeln("   <info>[3/3]</info> Recalculating metric config signatures...");
        $recalcCommand = new RecalculateMetricConfigSignaturesCommand($em);
        
        // We run the command internally using ArrayInput
        $input = new ArrayInput(['--dry-run' => false]);
        $input->setInteractive(false);
        $recalcCommand->run($input, $output);
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $conn = $em->getConnection();
        
        $output->writeln("   <info>[1/2]</info> Reverting geographic schema constraints and columns...");
        
        // We drop the fields that were added.
        try {
            $conn->executeStatement('ALTER TABLE metric_configs DROP CONSTRAINT IF EXISTS FK_METRIC_CONFIG_LOCATION;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP CONSTRAINT IF EXISTS FK_METRIC_CONFIG_STATE;');
            $conn->executeStatement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS FK_LOCATION_STATE;');
            $conn->executeStatement('ALTER TABLE locations DROP CONSTRAINT IF EXISTS FK_LOCATION_COUNTRY;');
            $conn->executeStatement('DROP INDEX IF EXISTS IDX_METRIC_CONFIGS_LOCATION;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP COLUMN IF EXISTS location_id;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP COLUMN IF EXISTS state_id;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP COLUMN IF EXISTS city_id;');
            
            // Revert GA4 and GBP optimized indexes
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_event_matrix_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_traffic_matrix_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_acquisition_matrix_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_gbp_lookup_idx;');
            
            // Revert event entities
            $conn->executeStatement('ALTER TABLE metric_configs DROP CONSTRAINT IF EXISTS FK_METRIC_CONFIGS_EVENT;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP CONSTRAINT IF EXISTS FK_METRIC_CONFIGS_CHANNELED_EVENT;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_lookup_event_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_lookup_channeled_event_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_event_idx;');
            $conn->executeStatement('DROP INDEX IF EXISTS idx_metric_configs_channeled_event_idx;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP COLUMN IF EXISTS event_id;');
            $conn->executeStatement('ALTER TABLE metric_configs DROP COLUMN IF EXISTS channeled_event_id;');
            $conn->executeStatement('DROP TABLE IF EXISTS channeled_events CASCADE;');
            $conn->executeStatement('DROP TABLE IF EXISTS events CASCADE;');
        } catch (\Exception $e) {
            $output->writeln("   <comment>Schema revert warning: " . $e->getMessage() . "</comment>");
        }

        $output->writeln("   <info>[2/2]</info> Recalculating metric config signatures for previous state...");
        $recalcCommand = new RecalculateMetricConfigSignaturesCommand($em);
        $input = new ArrayInput(['--dry-run' => false]);
        $input->setInteractive(false);
        $recalcCommand->run($input, $output);
    }
}
