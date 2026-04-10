<?php

namespace Commands;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiDriverCore\Interfaces\DimensionManagerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Classes\DimensionManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Anibalealvarezs\ApiSkeleton\Enums\Channel;
use Enums\Country as CountryEnum;
use Faker\Factory;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Ultra Realistic Seeder (Names, PlatformIDs, Hierarchies, Metrics)'
)]
class SeedDemoDataCommand extends Command implements SeederInterface
{
    private EntityManagerInterface $entityManager;
    private $faker;
    private Connection $conn;
    private array $bufferConfigs = [];
    private const BULK_SIZE = 2000;

    private array $ages = ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    private array $genders = ['Female', 'Male', 'Unknown'];
    private array $dimensionSetCache = [];

    public function getEntityManager(): EntityManagerInterface
    {
        return Helpers::getManager();
    }

    public function getDimensionManager(): DimensionManagerInterface
    {
        return new DimensionManager(Helpers::getManager());
    }

    public function getDates(int $days = 180): array
    {
        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-$i days"));
        }

        return $dates;
    }

    public function getDimensionSetInfo(string $age, string $gen): array
    {
        return $this->dimensionSetCache["$age|$gen"] ?? ['id' => 0, 'hash' => 'none'];
    }

    public function getEntityClass(string $type): string
    {
        return match($type) {
            'account' => Account::class,
            'channeled_account' => ChanneledAccount::class,
            'campaign' => Campaign::class,
            'channeled_campaign' => ChanneledCampaign::class,
            'channeled_ad_group' => ChanneledAdGroup::class,
            'channeled_ad' => ChanneledAd::class,
            'page' => Page::class,
            'post' => Post::class,
            'query' => Query::class,
            'country' => Country::class,
            'device' => Device::class,
            default => throw new \Exception("Unknown entity type: $type")
        };
    }

    public function getEnumClass(string $type): string
    {
        return match($type) {
            'channel' => Channel::class,
            'account_type' => Account::class,
            'country' => Country::class,
            'device' => Device::class,
            'period' => \Anibalealvarezs\ApiSkeleton\Enums\Period::class,
            default => throw new \Exception("Unknown enum type: $type")
        };
    }

    public function processMetricsMassive(\Doctrine\Common\Collections\Collection $metrics): void
    {
        if ($metrics->isEmpty()) {
            return;
        }

        try {
            $this->conn->beginTransaction();

            $pageMap = ['map' => []];
            $postMap = ['map' => []];
            $accountMap = ['map' => [], 'mapReverse' => []];

            \Classes\MetricsProcessor::processMetricConfigs(
                metrics: $metrics,
                manager: $this->entityManager,
                pageMap: $pageMap,
                postMap: $postMap,
                accountMap: $accountMap,
                logger: null
            );

            $this->entityManager->flush();
            $this->conn->commit();
        } catch (\Throwable $e) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }

            throw $e;
        }
    }

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->conn = $entityManager->getConnection();
        $this->faker = Factory::create('en_US');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('channels', 'c', InputOption::VALUE_OPTIONAL, 'Channels to seed (comma separated: facebook_marketing,facebook_organic,google_search_console)');
        $this->addOption('fresh', 'f', InputOption::VALUE_NONE, 'Wipe the entire database before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $allChannels = \Core\Drivers\DriverFactory::getAvailableChannels();
        $channelsInput = $input->getOption('channels');
        $channels = $channelsInput ? explode(',', $channelsInput) : $allChannels;
        $isFresh = $input->getOption('fresh') || ! $channelsInput;

        $output->writeln("<info>🚀 Seeding Realistic Demo Data...</info>");
        if ($isFresh) {
            $output->writeln("<comment>⚠️ Performing full database wipe...</comment>");
        } else {
            $output->writeln("<comment>📝 Performing partial update for channels: " . implode(', ', $channels) . "</comment>");
        }

        // --- 🧹 CLEAR REDIS CACHE ---
        try {
            $output->writeln("🧹 Flushing Redis cache...");
            Helpers::getRedisClient()->flushdb();
        } catch (\Throwable $e) {
            $output->writeln("<comment>⚠️ Redis Flush skipped: " . $e->getMessage() . "</comment>");
        }

        ini_set('memory_limit', '4G');
        set_time_limit(0);

        $platform = $this->conn->getDatabasePlatform();
        $isPostgres = str_contains(strtolower(get_class($platform)), 'postgre');

        if ($isPostgres) {
            $this->conn->executeStatement("SET session_replication_role = 'replica'");
        } else {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }

        if ($isFresh) {
            $output->writeln("🧹 Wiping tables...");
            $tables = ['channeled_metrics', 'metrics', 'metric_configs', 'dimension_set_items', 'dimension_sets', 'dimension_values', 'dimension_keys', 'channeled_ads', 'channeled_ad_groups', 'channeled_campaigns', 'campaigns', 'channeled_accounts', 'accounts', 'pages', 'queries', 'posts', 'countries', 'devices'];
            foreach ($tables as $table) {
                try {
                    $output->write("   - Truncating $table... ");
                    $truncateSql = $isPostgres ? "TRUNCATE TABLE $table RESTART IDENTITY CASCADE" : "TRUNCATE TABLE $table";
                    $this->conn->executeStatement($truncateSql);
                    $output->writeln("<info>OK</info>");
                } catch (\Throwable $e) {
                    $output->writeln("<comment>⚠️ Skipped: " . $e->getMessage() . "</comment>");
                }
            }
        } else {
            $output->writeln("🧹 Cleaning existing data for selected channels...");
            foreach ($channels as $chanName) {
                $chan = Channel::tryFromName($chanName);
                if (! $chan) {
                    $output->writeln("<comment>⚠️ Channel '$chanName' unknown, skipping cleanup.</comment>");

                    continue;
                }
                $chanId = $chan->value;
                $output->writeln("🧹 Cleaning existing data for channel: $chanName ($chanId)...");

                $this->conn->executeStatement("DELETE FROM channeled_metrics WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM metrics WHERE metric_config_id IN (SELECT id FROM metric_configs WHERE channel = ?)", [$chanId]);
                $this->conn->executeStatement("DELETE FROM metric_configs WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM channeled_ads WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM channeled_ad_groups WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM channeled_campaigns WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM channeled_accounts WHERE channel = ?", [$chanId]);
                $this->conn->executeStatement("DELETE FROM posts WHERE channeled_account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)", [$chanId]);
            }
        }

        $output->writeln("🛠️ Ensuring Basic Entities (Countries)...");
        $this->seedBasicEntities();

        $output->writeln("🛠️ Ensuring Dimension Hierarchy...");
        $this->seedDimensionHierarchy($output);

        foreach ($channels as $chanName) {
            try {
                $driver = \Core\Drivers\DriverFactory::get($chanName);
                if ($driver instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface) {
                    $output->writeln("🚀 Seeding channel: $chanName via Driver...");
                    $driver->seedDemoData($this, [
                        'output' => $output,
                        'progress' => new ProgressBar($output),
                    ]);
                    $this->flushAll();
                } else {
                    $output->writeln("<comment>⚠️ Driver for '$chanName' does not support modular seeding.</comment>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>❌ Error seeding '$chanName': " . $e->getMessage() . "</error>");
            }
        }

        $output->writeln("💾 Final flushing and synchronization...");
        $this->flushAll();

        if ($isPostgres) {
            $this->conn->executeStatement("SET session_replication_role = 'origin'");
        } else {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $output->writeln("\n<info>✅ Seeding Completed!</info>");

        return Command::SUCCESS;
    }

    private function generatePlatformId(): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 5)), 0, 15);
    }

    private function seedBasicEntities(): void
    {
        foreach ([CountryEnum::USA, CountryEnum::ESP, CountryEnum::MEX, CountryEnum::COL] as $c) {
            $existing = $this->conn->fetchOne("SELECT id FROM countries WHERE name = ?", [$c->getFullName()]);
            if ($existing) {
                continue;
            }

            $country = new Country();
            $country->addCode($c)->addName($c->getFullName());
            $this->entityManager->persist($country);
        }
        $this->entityManager->flush();
    }

    private function seedDimensionHierarchy(OutputInterface $output): void
    {
        $output->writeln("🛠️ Ensuring Dimensions...");

        // Age Key
        $ageK = $this->conn->fetchOne("SELECT id FROM dimension_keys WHERE name = 'age'");
        if (! $ageK) {
            $this->conn->insert('dimension_keys', ['name' => 'age', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $ageK = $this->conn->lastInsertId();
        }

        // Gender Key
        $genK = $this->conn->fetchOne("SELECT id FROM dimension_keys WHERE name = 'gender'");
        if (! $genK) {
            $this->conn->insert('dimension_keys', ['name' => 'gender', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $genK = $this->conn->lastInsertId();
        }

        $ageValIds = [];
        foreach ($this->ages as $age) {
            $id = $this->conn->fetchOne("SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?", [$ageK, $age]);
            if (! $id) {
                $this->conn->insert('dimension_values', ['dimension_key_id' => $ageK, 'value' => $age, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $id = $this->conn->lastInsertId();
            }
            $ageValIds[$age] = $id;
        }

        $genValIds = [];
        foreach ($this->genders as $gen) {
            $id = $this->conn->fetchOne("SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?", [$genK, $gen]);
            if (! $id) {
                $this->conn->insert('dimension_values', ['dimension_key_id' => $genK, 'value' => $gen, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $id = $this->conn->lastInsertId();
            }
            $genValIds[$gen] = $id;
        }

        foreach ($this->ages as $age) {
            foreach ($this->genders as $gen) {
                $dimensions = [
                    ['dimensionKey' => 'age', 'dimensionValue' => $age],
                    ['dimensionKey' => 'gender', 'dimensionValue' => $gen],
                ];
                $h = KeyGenerator::generateDimensionsHash($dimensions);
                $setId = $this->conn->fetchOne("SELECT id FROM dimension_sets WHERE hash = ?", [$h]);
                if (! $setId) {
                    $this->conn->insert('dimension_sets', ['hash' => $h, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    $setId = $this->conn->lastInsertId();
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $ageValIds[$age]]);
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $genValIds[$gen]]);
                }
                $this->dimensionSetCache["$age|$gen"] = ['id' => $setId, 'hash' => $h];
            }
        }
    }

    public function queueMetric(
        mixed $channel,
        string $name,
        string $date,
        mixed $value,
        $setId = null,
        $pageId = null,
        $adId = null,
        $agId = null,
        $cpId = null,
        $caId = null,
        $gAccId = null,
        $gCpId = null,
        $postId = null,
        $queryId = null,
        $countryId = null,
        $deviceId = null,
        $productId = null,
        $customerId = null,
        $orderId = null,
        $creativeId = null,
        ?string $accName = null,
        ?string $caPId = null,
        ?string $gCpPId = null,
        ?string $cpPId = null,
        ?string $agPId = null,
        ?string $adPId = null,
        ?string $pageUrl = null,
        ?string $postPId = null,
        ?string $queryPId = null,
        ?string $countryPId = null,
        ?string $devicePId = null,
        ?string $productPId = null,
        ?string $customerPId = null,
        ?string $orderPId = null,
        ?string $creativePId = null,
        ?string $data = null,
        ?string $setHash = null,
        ...$extraParams
    ): void {
        $sig = KeyGenerator::generateMetricConfigKey(
            channel: $channel instanceof \BackedEnum ? $channel->value : $channel,
            name: $name,
            period: 'daily',
            account: $accName,
            channeledAccount: $caPId,
            campaign: $gCpPId,
            channeledCampaign: $cpPId,
            channeledAdGroup: $agPId,
            channeledAd: $adPId,
            page: $pageUrl,
            query: $queryPId,
            post: $postPId,
            country: $countryPId,
            device: $devicePId,
            creative: $creativePId,
            product: $productPId,
            customer: $customerPId,
            order: $orderPId,
            dimensionSet: $setHash ?: $setId
        );
        $this->bufferConfigs[] = [
            'channel' => $channel->value,
            'name' => $name,
            'period' => 'daily',
            'metric_date' => $date,
            'page_id' => $pageId,
            'channeled_ad_id' => $adId,
            'channeled_ad_group_id' => $agId,
            'channeled_campaign_id' => $cpId,
            'campaign_id' => $gCpId,
            'channeled_account_id' => $caId,
            'account_id' => $gAccId,
            'post_id' => $postId,
            'query_id' => $queryId,
            'country_id' => $countryId,
            'device_id' => $deviceId,
            'creative_id' => $creativeId,
            'product_id' => $productId,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'config_signature' => $sig,
            'value' => (float)$value,
            'dimension_set_id' => $setId,
            'dimension_set_hash' => $setHash ?: ($setId ? null : KeyGenerator::generateDimensionsHash([])), // Default unsegmented hash for null ID
            'data' => $data,
        ];
        if (count($this->bufferConfigs) >= self::BULK_SIZE) {
            $this->flushAll();
        }
    }

    private function flushAll(): void
    {
        if (empty($this->bufferConfigs)) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $configsToInsert = [];
        foreach ($this->bufferConfigs as $row) {
            $configsToInsert[$row['config_signature']] = [
                'channel' => $row['channel'],
                'name' => $row['name'],
                'period' => 'daily',
                'page_id' => $row['page_id'],
                'post_id' => $row['post_id'],
                'channeled_ad_id' => $row['channeled_ad_id'],
                'channeled_ad_group_id' => $row['channeled_ad_group_id'],
                'channeled_campaign_id' => $row['channeled_campaign_id'],
                'campaign_id' => $row['campaign_id'],
                'channeled_account_id' => $row['channeled_account_id'],
                'account_id' => $row['account_id'],
                'query_id' => $row['query_id'],
                'country_id' => $row['country_id'],
                'device_id' => $row['device_id'],
                'creative_id' => $row['creative_id'],
                'product_id' => $row['product_id'],
                'customer_id' => $row['customer_id'],
                'order_id' => $row['order_id'],
                'dimension_set_id' => $row['dimension_set_id'],
                'config_signature' => $row['config_signature'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->bulkInsert('metric_configs', array_values($configsToInsert), ['dimension_set_id']);

        $quotedSigs = array_map(fn ($c) => $this->conn->quote($c['config_signature']), $this->bufferConfigs);
        $idMap = $this->conn->fetchAllKeyValue("SELECT config_signature, id FROM metric_configs WHERE config_signature IN (" . implode(',', $quotedSigs) . ")");

        $metricsToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $cfgId = $idMap[$c['config_signature']] ?? null;
            if (! $cfgId) {
                continue;
            }

            $dimHash = $c['dimension_set_hash'] ?? md5((string)$c['dimension_set_id']);
            $mKey = $cfgId . '|' . $dimHash . '|' . $c['metric_date'];

            $metricsToInsert[$mKey] = [
                'metric_config_id' => $cfgId,
                'dimensions_hash' => $dimHash,
                'value' => $c['value'],
                'metric_date' => $c['metric_date'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->bulkInsert('metrics', array_values($metricsToInsert), ['value']);

        $quotedConfigIds = array_map('intval', array_values($idMap));
        if (empty($quotedConfigIds)) {
            return;
        }

        $isPostgres = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
        $mMap = [];
        $mRows = $this->conn->fetchAllAssociative("SELECT id, metric_config_id, dimensions_hash, metric_date FROM metrics WHERE metric_config_id IN (" . implode(',', $quotedConfigIds) . ")");
        foreach ($mRows as $mRow) {
            $k = $mRow['metric_config_id'] . '|' . $mRow['dimensions_hash'] . '|' . $mRow['metric_date'];
            $mMap[$k] = $mRow['id'];
        }

        $chanToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $mKey = ($idMap[$c['config_signature']] ?? 0) . '|' . ($c['dimension_set_hash'] ?? md5((string)$c['dimension_set_id'])) . '|' . $c['metric_date'];
            $mId = $mMap[$mKey] ?? null;
            if ($mId) {
                $chanToInsert[] = [
                    'platform_id' => $this->generatePlatformId(),
                    'channel' => $c['channel'],
                    'metric_id' => $mId,
                    'dimension_set_id' => $c['dimension_set_id'],
                    'platform_created_at' => $c['metric_date'] . ' 00:00:00',
                    'data' => $c['data'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->bulkInsert('channeled_metrics', $chanToInsert);
        $this->bufferConfigs = [];
    }

    private function bulkInsert(string $table, array $rows, array $updateCols = []): void
    {
        if (empty($rows)) {
            return;
        }

        if (empty($updateCols)) {
            $columns = array_keys($rows[0]);
            $vals = [];
            foreach ($rows as $row) {
                $vals[] = "(" . implode(',', array_map(fn ($v) => is_null($v) ? 'NULL' : $this->conn->quote((string)$v), array_values($row))) . ")";
            }

            $isPostgres = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
            $insertSql = $isPostgres ? "INSERT INTO $table (" . implode(',', $columns) . ") VALUES " . implode(',', $vals) . " ON CONFLICT DO NOTHING" : "INSERT IGNORE INTO $table (" . implode(',', $columns) . ") VALUES " . implode(',', $vals);

            $this->conn->executeStatement($insertSql);

            return;
        }

        // Use buildUpsertSql if updateCols are provided
        $columns = array_keys($rows[0]);
        $numCols = count($columns);
        $chunkSize = (int)floor(60000 / $numCols);
        $uniqueCols = ($table === 'metric_configs') ? ['config_signature'] : (($table === 'metrics') ? ['metric_config_id', 'dimensions_hash', 'metric_date'] : []);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $sql = \Helpers\Helpers::buildUpsertSql($table, $columns, $updateCols, $uniqueCols, count($chunk));
            $params = [];
            foreach ($chunk as $row) {
                foreach ($row as $val) {
                    $params[] = $val;
                }
            }
            $this->conn->executeStatement($sql, $params);
        }
    }
}
