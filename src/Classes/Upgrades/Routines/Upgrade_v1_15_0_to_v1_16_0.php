<?php

namespace Classes\Upgrades\Routines;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Upgrade_v1_15_0_to_v1_16_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array
    {
        return ['1.15.0'];
    }

    public function getToVersion(): string
    {
        return '1.16.0';
    }

    public function getDescription(): string
    {
        return 'Adds TypeSafe AI Query Classification schema (query_classifications, account_query_classifications) and analytical grouping optimizations.';
    }

    public function requiresNuclearResync(): bool
    {
        return false;
    }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/2]</info> Creating TypeSafe AI classification tables...");
        $conn = $em->getConnection();

        $conn->executeStatement("
            CREATE TABLE IF NOT EXISTS query_classifications (
                query_id BIGINT NOT NULL PRIMARY KEY,
                intent VARCHAR(32) DEFAULT NULL,
                language VARCHAR(10) DEFAULT NULL,
                confidence DOUBLE PRECISION DEFAULT NULL,
                classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_qc_intent_query ON query_classifications (intent, query_id);
            CREATE INDEX IF NOT EXISTS idx_qc_lang_query ON query_classifications (language, query_id);

            CREATE TABLE IF NOT EXISTS account_query_classifications (
                channeled_account_id BIGINT NOT NULL,
                query_id BIGINT NOT NULL,
                brand_relation VARCHAR(32) NOT NULL,
                business_relevance VARCHAR(32) NOT NULL,
                confidence DOUBLE PRECISION DEFAULT NULL,
                classified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (channeled_account_id, query_id)
            );
            CREATE INDEX IF NOT EXISTS idx_aqc_asset_lookup ON account_query_classifications (channeled_account_id, query_id);
            CREATE INDEX IF NOT EXISTS idx_aqc_brand_filter ON account_query_classifications (channeled_account_id, brand_relation, query_id);
            CREATE INDEX IF NOT EXISTS idx_aqc_relevance_filter ON account_query_classifications (channeled_account_id, business_relevance, query_id);
        ");

        $output->writeln("   <info>[2/2]</info> Synchronizing Doctrine metadata...");
        try {
            $tool = new SchemaTool($em);
            $classes = $em->getMetadataFactory()->getAllMetadata();
            $tool->updateSchema($classes);
        } catch (\Throwable $e) {
            $output->writeln("   <comment>SchemaTool note: " . $e->getMessage() . "</comment>");
        }

        $output->writeln("   <info>v1.16.0 upgrade completed successfully.</info>");
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln("   <info>[1/1]</info> Reverting v1.16.0 upgrade routines...");
        $em->getConnection()->executeStatement("
            DROP TABLE IF EXISTS account_query_classifications;
            DROP TABLE IF EXISTS query_classifications;
        ");
    }
}
