<?php

declare(strict_types=1);

namespace Commands\Analytics;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
use Anibalealvarezs\ApiDriverCore\Drivers\DriverFactory;
use Anibalealvarezs\ApiDriverCore\Interfaces\DimensionManagerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Anibalealvarezs\ApiSkeleton\Enums\Period;
use BackedEnum;
use Classes\DimensionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channel;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Exception;
use Helpers\Helpers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'app:seed-google-ads-metrics',
    description: 'Generates realistic daily simulated performance metrics for existing Google Ads entities (last N days)'
)]
class SeedGoogleAdsMetricsCommand extends Command implements SeederInterface
{
    private EntityManagerInterface $entityManager;
    private Connection $conn;
    private array $bufferConfigs = [];
    private const BULK_SIZE = 2000;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->conn = $entityManager->getConnection();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generates realistic daily simulated performance metrics for existing Google Ads entities')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of daily history days to generate (default: 65)', 65)
            ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'Specific channeled account platform_id to target (optional)');
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function getDimensionManager(): DimensionManagerInterface
    {
        return new DimensionManager($this->entityManager);
    }

    public function getAges(): array
    {
        return ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    }

    public function getGenders(): array
    {
        return ['Female', 'Male', 'Unknown'];
    }

    public function getDates(int $days = 65): array
    {
        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-$i days"));
        }
        return $dates;
    }

    public function getDimensionSetInfo(string $age, string $gen): array
    {
        return ['id' => 0, 'hash' => 'none'];
    }

    public function getEntityClass(string $shortName): string
    {
        return match ($shortName) {
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
            default => throw new Exception("Unknown entity type: $shortName")
        };
    }

    public function getEnumClass(string $shortName): string
    {
        return match ($shortName) {
            'channel' => Channel::class,
            'period' => Period::class,
            default => throw new Exception("Unknown enum type: $shortName")
        };
    }

    public function resolveEntity(string $type, array $params): UniversalEntity
    {
        $class = $this->getEntityClass($type);
        $repo = $this->entityManager->getRepository($class);

        $criteria = [];
        if (isset($params['name'])) $criteria['name'] = $params['name'];
        if (isset($params['platformId'])) $criteria['platformId'] = $params['platformId'];
        if (isset($params['campaignId'])) $criteria['campaignId'] = $params['campaignId'];

        $entity = empty($criteria) ? null : $repo->findOneBy($criteria);

        if (!$entity) {
            $entity = new $class();
            if (isset($params['name']) && method_exists($entity, 'addName')) $entity->addName($params['name']);
            if (isset($params['platformId']) && method_exists($entity, 'addPlatformId')) $entity->addPlatformId($params['platformId']);
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        }

        $ue = new UniversalEntity();
        $ue->setPlatformId($entity->getPlatformId() ?? (method_exists($entity, 'getCampaignId') ? $entity->getCampaignId() : null))
            ->setTitle(method_exists($entity, 'getName') ? $entity->getName() : null);
        $ue->id = $entity->getId();
        return $ue;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int)$input->getOption('days');
        if ($days < 1) $days = 65;

        $targetAccount = $input->getOption('account');

        $channelObj = Channel::tryFromName('google_ads');
        if (!$channelObj) {
            $output->writeln('<error>Channel "google_ads" not found in database.</error>');
            return Command::FAILURE;
        }
        $channelId = (int)$channelObj->value;

        $output->writeln("<info>🚀 Populating simulated metrics for Google Ads (Channel ID: {$channelId}, Past {$days} days)...</info>");

        // 1. Locate channeled accounts
        $sqlAccounts = "SELECT id, platform_id, name, account_id FROM channeled_accounts WHERE channel = ?";
        $paramsAccounts = [$channelId];
        if ($targetAccount) {
            $sqlAccounts .= " AND platform_id = ?";
            $paramsAccounts[] = $targetAccount;
        }
        $chanAccounts = $this->conn->fetchAllAssociative($sqlAccounts, $paramsAccounts);

        if (empty($chanAccounts)) {
            $output->writeln("<comment>⚠️ No channeled accounts found for Google Ads. Please sync your Google Ads account first.</comment>");
            return Command::SUCCESS;
        }

        $dates = $this->getDates($days);
        $devices = ['DESKTOP', 'MOBILE', 'TABLET'];

        // Ensure devices exist in devices table
        $deviceMap = [];
        foreach ($devices as $devType) {
            $devId = $this->conn->fetchOne("SELECT id FROM devices WHERE type = ?", [strtolower($devType)]);
            if (!$devId) {
                $this->conn->insert('devices', ['type' => strtolower($devType)]);
                $devId = $this->conn->lastInsertId();
            }
            $deviceMap[$devType] = (int)$devId;
        }

        $totalMetricsQueued = 0;

        foreach ($chanAccounts as $ca) {
            $caId = (int)$ca['id'];
            $caPId = (string)$ca['platform_id'];
            $caName = (string)($ca['name'] ?? $caPId);
            $gAccId = !empty($ca['account_id']) ? (int)$ca['account_id'] : null;

            $output->writeln("<info>📂 Processing Account: {$caName} ({$caPId})...</info>");

            // Fetch campaigns
            $campaigns = $this->conn->fetchAllAssociative(
                "SELECT id, platform_id, name, campaign_id, data FROM channeled_campaigns WHERE channeled_account_id = ?",
                [$caId]
            );

            if (empty($campaigns)) {
                $output->writeln("   <comment>No campaigns found for account {$caPId}. Skipping.</comment>");
                continue;
            }

            $output->writeln("   Found " . count($campaigns) . " campaigns. Generating time-series metrics...");
            $progressBar = new ProgressBar($output, count($campaigns) * count($dates));
            $progressBar->start();

            foreach ($campaigns as $cp) {
                $cpId = (int)$cp['id'];
                $cpPId = (string)$cp['platform_id'];
                $gCpId = !empty($cp['campaign_id']) ? (int)$cp['campaign_id'] : null;
                $cpData = !empty($cp['data']) ? (is_string($cp['data']) ? json_decode($cp['data'], true) : $cp['data']) : [];
                $channelType = $cpData['advertising_channel_type'] ?? 'SEARCH';
                $isCampaignScopeOnly = in_array($channelType, ['PERFORMANCE_MAX', 'SHOPPING'], true);

                // Fetch ad groups & ads for this campaign
                $adGroups = $this->conn->fetchAllAssociative(
                    "SELECT id, platform_id, name FROM channeled_ad_groups WHERE channeled_campaign_id = ?",
                    [$cpId]
                );

                $adGroupsWithAds = [];
                foreach ($adGroups as $ag) {
                    $agId = (int)$ag['id'];
                    $agPId = (string)$ag['platform_id'];
                    $ads = $this->conn->fetchAllAssociative(
                        "SELECT id, platform_id, name FROM channeled_ads WHERE channeled_ad_group_id = ?",
                        [$agId]
                    );
                    $adGroupsWithAds[] = [
                        'agId'  => $agId,
                        'agPId' => $agPId,
                        'ads'   => $ads
                    ];
                }

                foreach ($dates as $date) {
                    // Generate performance baseline with weekday fluctuations
                    $dayOfWeek = (int)date('w', strtotime($date));
                    $dayMultiplier = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 0.75 : 1.15;

                    if ($isCampaignScopeOnly || empty($adGroupsWithAds)) {
                        // Seed directly at campaign scope (PMax, Shopping, or campaign with no ad children)
                        foreach ($devices as $deviceType) {
                            $devWeight = $deviceType === 'MOBILE' ? 0.55 : ($deviceType === 'DESKTOP' ? 0.35 : 0.10);
                            $imps = (int)(rand(200, 800) * $dayMultiplier * $devWeight);
                            $ctr = rand(25, 75) / 1000; // 2.5% to 7.5%
                            $clicks = max(1, (int)round($imps * $ctr));
                            $cpc = rand(60, 220) / 100; // $0.60 to $2.20
                            $spend = round($clicks * $cpc, 2);
                            $convRate = rand(30, 90) / 1000; // 3.0% to 9.0%
                            $conversions = max(0, (int)round($clicks * $convRate));
                            $convVal = $conversions > 0 ? round($conversions * rand(40, 140), 2) : 0.0;
                            $roas = $spend > 0 ? round($convVal / $spend, 2) : 0.0;
                            $costPerConv = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;

                            $metricsData = [
                                'spend'               => $spend,
                                'clicks'              => $clicks,
                                'impressions'         => $imps,
                                'conversions'         => $conversions,
                                'conversion_value'    => $convVal,
                                'ctr'                 => $imps > 0 ? round($clicks / $imps, 4) : 0.0,
                                'cpc'                 => $clicks > 0 ? round($spend / $clicks, 2) : 0.0,
                                'cost_per_conversion' => $costPerConv,
                                'conversion_rate'     => $clicks > 0 ? round($conversions / $clicks, 4) : 0.0,
                                'roas_purchase'       => $roas,
                            ];

                            foreach ($metricsData as $metricName => $metricVal) {
                                $this->queueMetric(
                                    channel: $channelObj,
                                    name: $metricName,
                                    date: $date,
                                    value: $metricVal,
                                    caId: $caId,
                                    cpId: $cpId,
                                    gAccId: $gAccId,
                                    gCpId: $gCpId,
                                    deviceId: $deviceMap[$deviceType],
                                    accName: $caName,
                                    caPId: $caPId,
                                    cpPId: $cpPId,
                                    devicePId: strtolower($deviceType),
                                    data: json_encode($metricsData)
                                );
                                $totalMetricsQueued++;
                            }
                        }
                    } else {
                        // Standard scope with ad groups and ads
                        foreach ($adGroupsWithAds as $agData) {
                            $agId = $agData['agId'];
                            $agPId = $agData['agPId'];
                            $ads = $agData['ads'];

                            if (empty($ads)) {
                                $ads = [['id' => null, 'platform_id' => null]];
                            }

                            foreach ($ads as $ad) {
                                $adId = !empty($ad['id']) ? (int)$ad['id'] : null;
                                $adPId = !empty($ad['platform_id']) ? (string)$ad['platform_id'] : null;

                                foreach ($devices as $deviceType) {
                                    $devWeight = $deviceType === 'MOBILE' ? 0.60 : ($deviceType === 'DESKTOP' ? 0.32 : 0.08);
                                    $imps = (int)(rand(100, 500) * $dayMultiplier * $devWeight);
                                    $ctr = rand(20, 80) / 1000;
                                    $clicks = max(1, (int)round($imps * $ctr));
                                    $cpc = rand(50, 180) / 100;
                                    $spend = round($clicks * $cpc, 2);
                                    $convRate = rand(25, 85) / 1000;
                                    $conversions = max(0, (int)round($clicks * $convRate));
                                    $convVal = $conversions > 0 ? round($conversions * rand(35, 120), 2) : 0.0;
                                    $roas = $spend > 0 ? round($convVal / $spend, 2) : 0.0;
                                    $costPerConv = $conversions > 0 ? round($spend / $conversions, 2) : 0.0;

                                    $metricsData = [
                                        'spend'               => $spend,
                                        'clicks'              => $clicks,
                                        'impressions'         => $imps,
                                        'conversions'         => $conversions,
                                        'conversion_value'    => $convVal,
                                        'ctr'                 => $imps > 0 ? round($clicks / $imps, 4) : 0.0,
                                        'cpc'                 => $clicks > 0 ? round($spend / $clicks, 2) : 0.0,
                                        'cost_per_conversion' => $costPerConv,
                                        'conversion_rate'     => $clicks > 0 ? round($conversions / $clicks, 4) : 0.0,
                                        'roas_purchase'       => $roas,
                                    ];

                                    foreach ($metricsData as $metricName => $metricVal) {
                                        $this->queueMetric(
                                            channel: $channelObj,
                                            name: $metricName,
                                            date: $date,
                                            value: $metricVal,
                                            adId: $adId,
                                            agId: $agId,
                                            cpId: $cpId,
                                            caId: $caId,
                                            gAccId: $gAccId,
                                            gCpId: $gCpId,
                                            deviceId: $deviceMap[$deviceType],
                                            accName: $caName,
                                            caPId: $caPId,
                                            cpPId: $cpPId,
                                            agPId: $agPId,
                                            adPId: $adPId,
                                            devicePId: strtolower($deviceType),
                                            data: json_encode($metricsData)
                                        );
                                        $totalMetricsQueued++;
                                    }
                                }
                            }
                        }
                    }
                    $progressBar->advance();
                }
            }
            $progressBar->finish();
            $output->writeln("");
        }

        $output->writeln("💾 Flushing final metrics to database...");
        $this->flushAll();

        // Flush Redis Cache so dashboard loads fresh data immediately
        try {
            $output->writeln("🧹 Clearing Redis cache for Google Ads...");
            Helpers::getRedisClient()->flushdb();
            $output->writeln("<info>✔ Redis cache flushed.</info>");
        } catch (Throwable $e) {
            $output->writeln("<comment>⚠️ Redis flush warning: " . $e->getMessage() . "</comment>");
        }

        $output->writeln("<info>✅ Success! Populated {$totalMetricsQueued} metric points for Google Ads across the last {$days} days.</info>");
        return Command::SUCCESS;
    }

    public function queueMetric(
        mixed   $channel,
        string  $name,
        string  $date,
        mixed   $value,
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
        $channelVal = $channel instanceof BackedEnum ? $channel->value : ($channel instanceof Channel ? $channel->value : (is_numeric($channel) ? (int)$channel : $channel));

        $sig = KeyGenerator::generateMetricConfigKey(
            channel: $channelVal,
            name: $name,
            period: 'daily',
            account: $accName,
            channeledAccount: $caPId,
            campaign: $gCpPId,
            channeledCampaign: $cpPId,
            channeledAdGroup: $agPId,
            channeledAd: $adPId,
            creative: $creativePId,
            page: $pageUrl,
            query: $queryPId,
            post: $postPId,
            product: $productPId,
            customer: $customerPId,
            order: $orderPId,
            country: $countryPId,
            device: $devicePId,
            dimensionSet: $setHash ?: $setId,
            location: null,
            state: null,
            city: null
        );

        $this->bufferConfigs[] = [
            'channel'               => $channelVal,
            'name'                  => $name,
            'period'                => 'daily',
            'metric_date'           => $date,
            'page_id'               => $pageId,
            'channeled_ad_id'       => $adId,
            'channeled_ad_group_id' => $agId,
            'channeled_campaign_id' => $cpId,
            'campaign_id'           => $gCpId,
            'channeled_account_id'  => $caId,
            'account_id'            => $gAccId,
            'post_id'               => $postId,
            'query_id'              => $queryId,
            'country_id'            => $countryId,
            'device_id'             => $deviceId,
            'creative_id'           => $creativeId,
            'product_id'            => $productId,
            'customer_id'           => $customerId,
            'order_id'              => $orderId,
            'config_signature'      => $sig,
            'value'                 => (float)$value,
            'dimension_set_id'      => $setId,
            'dimension_set_hash'    => $setHash ?: ($setId ? null : KeyGenerator::generateDimensionsHash([])),
            'data'                  => $data,
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
                'channel'               => $row['channel'],
                'name'                  => $row['name'],
                'period'                => 'daily',
                'page_id'               => $row['page_id'],
                'post_id'               => $row['post_id'],
                'channeled_ad_id'       => $row['channeled_ad_id'],
                'channeled_ad_group_id' => $row['channeled_ad_group_id'],
                'channeled_campaign_id' => $row['channeled_campaign_id'],
                'campaign_id'           => $row['campaign_id'],
                'channeled_account_id'  => $row['channeled_account_id'],
                'account_id'            => $row['account_id'],
                'query_id'              => $row['query_id'],
                'country_id'            => $row['country_id'],
                'device_id'             => $row['device_id'],
                'creative_id'           => $row['creative_id'],
                'product_id'            => $row['product_id'],
                'customer_id'           => $row['customer_id'],
                'order_id'              => $row['order_id'],
                'dimension_set_id'      => $row['dimension_set_id'],
                'config_signature'      => $row['config_signature'],
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        $this->bulkInsert('metric_configs', array_values($configsToInsert), ['dimension_set_id']);

        $quotedSigs = array_map(fn($c) => $this->conn->quote($c['config_signature']), $this->bufferConfigs);
        $idMap = $this->conn->fetchAllKeyValue(
            "SELECT config_signature, id FROM metric_configs WHERE config_signature IN (" . implode(',', $quotedSigs) . ")"
        );

        $metricsToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $cfgId = $idMap[$c['config_signature']] ?? null;
            if (!$cfgId) {
                continue;
            }

            $dimHash = $c['dimension_set_hash'] ?? md5((string)$c['dimension_set_id']);
            $mKey = $cfgId . '|' . $dimHash . '|' . $c['metric_date'];

            $metricsToInsert[$mKey] = [
                'metric_config_id' => $cfgId,
                'dimensions_hash'  => $dimHash,
                'value'            => $c['value'],
                'metric_date'      => $c['metric_date'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }
        $this->bulkInsert('metrics', array_values($metricsToInsert), ['value']);

        $quotedConfigIds = array_map('intval', array_values($idMap));
        if (empty($quotedConfigIds)) {
            $this->bufferConfigs = [];
            return;
        }

        $mMap = [];
        $mRows = $this->conn->fetchAllAssociative(
            "SELECT id, metric_config_id, dimensions_hash, metric_date FROM metrics WHERE metric_config_id IN (" . implode(',', $quotedConfigIds) . ")"
        );
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
                    'platform_id'         => substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 5)), 0, 15),
                    'channel'             => $c['channel'],
                    'metric_id'           => $mId,
                    'dimension_set_id'    => $c['dimension_set_id'],
                    'platform_created_at' => $c['metric_date'] . ' 00:00:00',
                    'data'                => $c['data'],
                    'created_at'          => $now,
                    'updated_at'          => $now,
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

        $isPostgres = $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $first = reset($rows);
        $cols = array_keys($first);
        $colNames = implode(', ', $cols);

        foreach (array_chunk($rows, 500) as $chunk) {
            $valuesSql = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($cols as $col) {
                    $v = $row[$col] ?? null;
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_numeric($v)) {
                        $vals[] = $v;
                    } else {
                        $vals[] = $this->conn->quote((string)$v);
                    }
                }
                $valuesSql[] = '(' . implode(', ', $vals) . ')';
            }

            $sql = "INSERT INTO $table ($colNames) VALUES " . implode(', ', $valuesSql);

            if ($isPostgres) {
                if (!empty($updateCols)) {
                    $updates = [];
                    foreach ($updateCols as $c) {
                        $updates[] = "$c = EXCLUDED.$c";
                    }
                    if ($table === 'metric_configs') {
                        $sql .= " ON CONFLICT (config_signature) DO UPDATE SET " . implode(', ', $updates);
                    } elseif ($table === 'metrics') {
                        $sql .= " ON CONFLICT (metric_config_id, dimensions_hash, metric_date) DO UPDATE SET " . implode(', ', $updates);
                    } else {
                        $sql .= " ON CONFLICT DO NOTHING";
                    }
                } else {
                    $sql .= " ON CONFLICT DO NOTHING";
                }
            } else {
                if (!empty($updateCols)) {
                    $updates = [];
                    foreach ($updateCols as $c) {
                        $updates[] = "$c = VALUES($c)";
                    }
                    $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
                } else {
                    $sql = "INSERT IGNORE INTO $table ($colNames) VALUES " . implode(', ', $valuesSql);
                }
            }

            try {
                $this->conn->executeStatement($sql);
            } catch (Throwable $e) {
                // Ignore duplicate errors or log if unexpected
            }
        }
    }
}
