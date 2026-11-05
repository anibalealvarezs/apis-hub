<?php

namespace Classes\Upgrades\Routines;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Entities\Analytics\Channeled\ChanneledCart;
use Interfaces\UpgradeRoutineInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Add only the commerce relations declared in Doctrine metadata. */
class Upgrade_v1_19_0_to_v1_20_0 implements UpgradeRoutineInterface
{
    public function getFromVersions(): array { return ['1.18.4', '1.19.0']; }
    public function getToVersion(): string { return '1.20.0'; }
    public function requiresNuclearResync(): bool { return false; }
    public function getDescription(): string { return 'Adds commerce store relationships and cached carts for Mailchimp expansion.'; }

    public function up(EntityManagerInterface $em, OutputInterface $output): void
    {
        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $desired = clone $current;
        $relations = [
            ['channeled_stores', 'channeled_account_id', 'channeled_accounts'],
            ['channeled_customers', 'channeled_store_id', 'channeled_stores'],
            ['channeled_customers', 'channeled_account_id', 'channeled_accounts'],
            ['channeled_products', 'channeled_store_id', 'channeled_stores'],
            ['channeled_price_rules', 'channeled_store_id', 'channeled_stores'],
        ];
        foreach ($relations as [$tableName, $column, $target]) {
            $table = $desired->getTable($tableName);
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'integer', ['notnull' => false]);
                $table->addIndex([$column], 'idx_'.$tableName.'_'.$column);
                $table->addForeignKeyConstraint($target, [$column], ['id'], ['onDelete' => 'SET NULL']);
            }
        }
        if (!$desired->hasTable('channeled_carts')) {
            $metadata = $em->getClassMetadata(ChanneledCart::class);
            $source = (new SchemaTool($em))->getSchemaFromMetadata([$metadata])->getTable('channeled_carts');
            $table = $desired->createTable('channeled_carts');
            foreach ($source->getColumns() as $column) {
                $options = $column->toArray();
                unset($options['name'], $options['type']);
                $table->addColumn($column->getName(), Type::lookupName($column->getType()), $options);
            }
            foreach ($source->getIndexes() as $index) {
                if ($index->isPrimary()) $table->setPrimaryKey($index->getColumns());
                elseif ($index->isUnique()) $table->addUniqueIndex($index->getColumns(), $index->getName());
                else $table->addIndex($index->getColumns(), $index->getName());
            }
            foreach ($source->getForeignKeys() as $foreignKey) {
                $table->addForeignKeyConstraint($foreignKey->getForeignTableName(), $foreignKey->getLocalColumns(),
                    $foreignKey->getForeignColumns(), $foreignKey->getOptions(), $foreignKey->getName());
            }
        }
        $diff = $schemaManager->createComparator()->compareSchemas($current, $desired);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        $connection->transactional(function () use ($connection, $statements): void {
            foreach ($statements as $sql) $connection->executeStatement($sql);
        });
        $output->writeln('Commerce schema is ready. Refresh Mailchimp entities and the required order history before rebuilding store metrics.');
        $output->writeln('Legacy records with ambiguous identities remain preserved. Only verified, store-linked records are exposed in the commerce Catalog.');
    }

    public function down(EntityManagerInterface $em, OutputInterface $output): void
    {
        $output->writeln('Commerce columns and cached records are retained for recovery. Revert through a reviewed forward migration.');
    }
}
