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
        return 'Adds geo location fields (location, state, city) to metric_configs and recalculates signatures.';
    }

    public function requiresNuclearResync(): bool
    {
        // Signatures are recalculated safely, so we don't strictly require a full database wipe.
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $conn = $em->getConnection();
        
        // 1. Run the raw SQL migration
        $sqlPath = __DIR__ . '/../../../../migrations/Version20260617000001_add_location_geo_entities.sql';
        if (file_exists($sqlPath)) {
            $output->writeln("   <info>[1/3]</info> Executing raw SQL migration...");
            $sql = file_get_contents($sqlPath);
            if (!empty(trim($sql))) {
                $conn->executeStatement($sql);
            }
        } else {
            $output->writeln("   <comment>[1/3]</comment> SQL migration file not found. Skipping.");
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
