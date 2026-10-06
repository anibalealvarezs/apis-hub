<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_16_0_to_v1_17_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.16.0'];
    }

    public function getToVersion(): string
    {
        return '1.17.0';
    }

    public function getDescription(): string
    {
        return 'Adds type column and index to channeled_campaigns table with Mailchimp automation/regular separation.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/3]</info> Adding type column and index to channeled_campaigns...");
        $conn = $em->getConnection();

        $conn->executeStatement("
            ALTER TABLE channeled_campaigns ADD COLUMN IF NOT EXISTS type VARCHAR(32) DEFAULT NULL;
            CREATE INDEX IF NOT EXISTS idx_channeled_campaigns_channel_type_idx ON channeled_campaigns (channel, type);
        ");

        $output->writeln("   <info>[2/3]</info> Backfilling Mailchimp campaign types from JSON data...");
        $conn->executeStatement("
            UPDATE channeled_campaigns
            SET type = COALESCE(data->>'type', 'regular')
            WHERE channel = (SELECT id FROM channels WHERE name = 'mailchimp' LIMIT 1)
              AND type IS NULL;
        ");

        $output->writeln("   <info>[3/3]</info> Synchronizing Doctrine metadata...");
        try {
            $tool = new SchemaTool($em);
            $classes = $em->getMetadataFactory()->getAllMetadata();
            $tool->updateSchema($classes);
        } catch (\Throwable $e) {
            $output->writeln("   <comment>SchemaTool note: " . $e->getMessage() . "</comment>");
        }

        $output->writeln("   <info>v1.17.0 upgrade completed successfully.</info>");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.17.0 upgrade routines...");
        $em->getConnection()->executeStatement("
            DROP INDEX IF EXISTS idx_channeled_campaigns_channel_type_idx;
            ALTER TABLE channeled_campaigns DROP COLUMN IF EXISTS type;
        ");
    }
}
