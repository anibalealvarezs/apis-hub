<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_17_0_to_v1_18_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.17.0', '1.17.1', '1.17.2'];
    }

    public function getToVersion(): string
    {
        return '1.18.0';
    }

    public function getDescription(): string
    {
        return 'Registers google_ads channel and adds performance indexes on channeled entities for Google Ads.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $conn = $em->getConnection();

        $output->writeln("   <info>[1/3]</info> Registering Google Ads provider and channel...");
        $conn->executeStatement("
            INSERT INTO providers (name, label, created_at, updated_at)
            VALUES ('google', 'Google', NOW(), NOW())
            ON CONFLICT (name) DO NOTHING;

            INSERT INTO channels (name, label, icon, cooldown, maxworkers, tier, provider_id, created_at, updated_at)
            SELECT 'google_ads', 'Google Ads', 'G', 600, 2, 2, p.id, NOW(), NOW()
            FROM providers p
            WHERE p.name = 'google'
            ON CONFLICT (name) DO UPDATE
            SET label = EXCLUDED.label,
                tier = EXCLUDED.tier;
        ");

        $output->writeln("   <info>[2/3]</info> Creating channeled indexes for Google Ads marketing entities...");
        $conn->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_channeled_campaigns_channel_account_idx 
                ON channeled_campaigns (channel, channeled_account_id);

            CREATE INDEX IF NOT EXISTS idx_channeled_ad_groups_channel_account_idx 
                ON channeled_ad_groups (channel, channeled_account_id);

            CREATE INDEX IF NOT EXISTS idx_channeled_ads_channel_account_idx 
                ON channeled_ads (channel, channeled_account_id);
        ");

        $output->writeln("   <info>[3/3]</info> Synchronizing Doctrine metadata...");
        try {
            $tool = new SchemaTool($em);
            $classes = $em->getMetadataFactory()->getAllMetadata();
            $tool->updateSchema($classes);
        } catch (\Throwable $e) {
            $output->writeln("   <comment>SchemaTool note: " . $e->getMessage() . "</comment>");
        }

        $output->writeln("   <info>v1.18.0 upgrade completed successfully.</info>");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.18.0 upgrade routines...");
        $conn = $em->getConnection();

        $conn->executeStatement("
            DROP INDEX IF EXISTS idx_channeled_ads_channel_account_idx;
            DROP INDEX IF EXISTS idx_channeled_ad_groups_channel_account_idx;
            DROP INDEX IF EXISTS idx_channeled_campaigns_channel_account_idx;

            DELETE FROM channels WHERE name = 'google_ads';
        ");
    }
}
