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
use Doctrine\Common\Collections\Collection;
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
            ->setDescription('Generates realistic daily simulated performance metrics for Google Ads entities and search terms')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of daily history days to generate (default: 65)', 65)
            ->addOption('account', 'a', InputOption::VALUE_OPTIONAL, 'Specific channeled account platform_id to target (optional)')
            ->addOption('init-hierarchy', 'i', InputOption::VALUE_NONE, 'Automatically create demo account, campaigns (Search, PMax, Video), ad groups, ads, and search terms if missing');
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

    public function processMetricsMassive(Collection $metrics): void
    {
        // Unused in direct SQL time-series queueing, but fulfills SeederInterface contract
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
        $initHierarchy = (bool)$input->getOption('init-hierarchy');
        if ($initHierarchy || empty($chanAccounts)) {
            $output->writeln("<info>🛠 Ensuring demo Google Ads hierarchy and search entities...</info>");
            $this->ensureDemoHierarchy($channelId, $targetAccount);
            $chanAccounts = $this->conn->fetchAllAssociative($sqlAccounts, $paramsAccounts);
        }

        if (empty($chanAccounts)) {
            $output->writeln("<comment>⚠️ No channeled accounts found for Google Ads. Use --init-hierarchy to create demo accounts.</comment>");
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

            // Fetch campaigns (name is stored in campaigns table via campaign_id relation)
            $campaigns = $this->conn->fetchAllAssociative(
                "SELECT cc.id, cc.platform_id, COALESCE(c.name, cc.platform_id) AS name, cc.campaign_id, cc.data 
                 FROM channeled_campaigns cc
                 LEFT JOIN campaigns c ON c.id = cc.campaign_id
                 WHERE cc.channeled_account_id = ?",
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

                    // Seed Search Terms / Queries for SEARCH campaigns
                    if (str_contains(strtoupper($channelType), 'SEARCH')) {
                        $searchQueries = [
                            ['text' => 'buy running shoes online', 'is_wasted' => false, 'conv_rate' => 0.08, 'cpc_range' => [1.20, 2.80]],
                            ['text' => 'best trail running shoes 2026', 'is_wasted' => false, 'conv_rate' => 0.06, 'cpc_range' => [1.00, 2.20]],
                            ['text' => 'running shoes discount code', 'is_wasted' => false, 'conv_rate' => 0.12, 'cpc_range' => [0.80, 1.80]],
                            ['text' => 'lightweight marathon racing sneakers', 'is_wasted' => false, 'conv_rate' => 0.05, 'cpc_range' => [1.50, 3.50]],
                            ['text' => 'free running shoe pattern download', 'is_wasted' => true, 'conv_rate' => 0.0, 'cpc_range' => [0.90, 2.00]],
                            ['text' => 'how to make shoes at home tutorial', 'is_wasted' => true, 'conv_rate' => 0.0, 'cpc_range' => [0.70, 1.60]],
                            ['text' => 'counterfeit sneakers repair near me', 'is_wasted' => true, 'conv_rate' => 0.0, 'cpc_range' => [0.80, 1.90]],
                        ];

                        foreach ($searchQueries as $sq) {
                            $qText = $sq['text'];
                            $qId = $this->conn->fetchOne("SELECT id FROM queries WHERE query = ?", [$qText]);
                            if (!$qId) {
                                $this->conn->insert('queries', [
                                    'query' => $qText,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);
                                $qId = $this->conn->lastInsertId();
                            }
                            $qId = (int)$qId;

                            $firstAg = !empty($adGroupsWithAds[0]) ? $adGroupsWithAds[0] : null;
                            $targetAgId = $firstAg ? $firstAg['agId'] : null;
                            $targetAgPId = $firstAg ? $firstAg['agPId'] : null;

                            $qImps = (int)(rand(40, 250) * $dayMultiplier);
                            $qCtr = rand(30, 90) / 1000;
                            $qClicks = max(1, (int)round($qImps * $qCtr));
                            $qCpc = rand((int)($sq['cpc_range'][0] * 100), (int)($sq['cpc_range'][1] * 100)) / 100;
                            $qSpend = round($qClicks * $qCpc, 2);
                            $qConvs = $sq['is_wasted'] ? 0 : max(0, (int)round($qClicks * $sq['conv_rate']));
                            $qConvVal = $qConvs > 0 ? round($qConvs * rand(45, 130), 2) : 0.0;
                            $qRoas = $qSpend > 0 ? round($qConvVal / $qSpend, 2) : 0.0;
                            $qCostPerConv = $qConvs > 0 ? round($qSpend / $qConvs, 2) : 0.0;

                            $qMetrics = [
                                'spend'               => $qSpend,
                                'clicks'              => $qClicks,
                                'impressions'         => $qImps,
                                'conversions'         => $qConvs,
                                'conversion_value'    => $qConvVal,
                                'ctr'                 => $qImps > 0 ? round($qClicks / $qImps, 4) : 0.0,
                                'cpc'                 => $qClicks > 0 ? round($qSpend / $qClicks, 2) : 0.0,
                                'cost_per_conversion' => $qCostPerConv,
                                'conversion_rate'     => $qClicks > 0 ? round($qConvs / $qClicks, 4) : 0.0,
                                'roas_purchase'       => $qRoas,
                            ];

                            foreach ($qMetrics as $mName => $mVal) {
                                $this->queueMetric(
                                    channel: $channelObj,
                                    name: $mName,
                                    date: $date,
                                    value: $mVal,
                                    agId: $targetAgId,
                                    cpId: $cpId,
                                    caId: $caId,
                                    gAccId: $gAccId,
                                    gCpId: $gCpId,
                                    queryId: $qId,
                                    accName: $caName,
                                    caPId: $caPId,
                                    cpPId: $cpPId,
                                    agPId: $targetAgPId,
                                    queryPId: $qText,
                                    data: json_encode($qMetrics)
                                );
                                $totalMetricsQueued++;
                            }
                        }
                    }

                    // Seed YouTube / Video metrics for VIDEO campaigns
                    if (str_contains(strtoupper($channelType), 'VIDEO')) {
                        $videoViews = (int)(rand(300, 1500) * $dayMultiplier);
                        $this->queueMetric(
                            channel: $channelObj,
                            name: 'video_views',
                            date: $date,
                            value: $videoViews,
                            cpId: $cpId,
                            caId: $caId,
                            gAccId: $gAccId,
                            gCpId: $gCpId,
                            accName: $caName,
                            caPId: $caPId,
                            cpPId: $cpPId,
                            data: json_encode(['video_views' => $videoViews])
                        );
                        $totalMetricsQueued++;
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
                    'platform_id'         => 'seed-cm-' . $c['channel'] . '-' . $mId,
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
        $this->bulkInsert('channeled_metrics', $chanToInsert, ['data', 'updated_at']);
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

    private function ensureDemoHierarchy(int $channelId, ?string $targetAccount = null): void
    {
        $platformId = $targetAccount ?: 'demo-gads-101';
        $now = date('Y-m-d H:i:s');

        // 1. Account
        $accId = $this->conn->fetchOne("SELECT id FROM accounts WHERE name = 'Demo Business Account'");
        if (!$accId) {
            $this->conn->insert('accounts', [
                'name' => 'Demo Business Account',
                'created_at' => $now,
                'updated_at' => $now
            ]);
            $accId = $this->conn->lastInsertId();
        }
        $accId = (int)$accId;

        // 2. Channeled Account
        $caId = $this->conn->fetchOne("SELECT id FROM channeled_accounts WHERE channel = ? AND platform_id = ?", [$channelId, $platformId]);
        if (!$caId) {
            $this->conn->insert('channeled_accounts', [
                'channel' => $channelId,
                'platform_id' => $platformId,
                'account_id' => $accId,
                'name' => 'Google Ads Demo Account (' . $platformId . ')',
                'enabled' => 1,
                'data' => json_encode(['currency' => 'USD', 'time_zone' => 'America/New_York']),
                'created_at' => $now,
                'updated_at' => $now
            ]);
            $caId = $this->conn->lastInsertId();
        }
        $caId = (int)$caId;

        // 3. Demo Campaigns
        $demoCampaigns = [
            [
                'pId' => 'camp-search-brand',
                'name' => 'Search - Brand & High-Intent Products',
                'type' => 'SEARCH',
                'budget' => 150.00,
                'adGroups' => [
                    [
                        'pId' => 'ag-brand-core',
                        'name' => 'Core Running Shoes',
                        'ads' => [
                            ['pId' => 'ad-brand-headline-1', 'name' => 'Official Running Shoes Store - Free 2-Day Shipping'],
                            ['pId' => 'ad-brand-headline-2', 'name' => 'Top Rated Trail Runners - Exclusive Online Discounts'],
                        ]
                    ],
                    [
                        'pId' => 'ag-brand-apparel',
                        'name' => 'Athletic Apparel & Gear',
                        'ads' => [
                            ['pId' => 'ad-apparel-1', 'name' => 'Pro Running Apparel - Engineered For Speed'],
                        ]
                    ]
                ]
            ],
            [
                'pId' => 'camp-search-competitor',
                'name' => 'Search - Competitor & Broad Category',
                'type' => 'SEARCH',
                'budget' => 100.00,
                'adGroups' => [
                    [
                        'pId' => 'ag-broad-category',
                        'name' => 'Broad Marathon Training',
                        'ads' => [
                            ['pId' => 'ad-broad-1', 'name' => 'Marathon Preparation Shoes - Find Your Perfect Fit'],
                        ]
                    ]
                ]
            ],
            [
                'pId' => 'camp-pmax-sales',
                'name' => 'Performance Max - Omnichannel Sales',
                'type' => 'PERFORMANCE_MAX',
                'budget' => 200.00,
                'adGroups' => []
            ],
            [
                'pId' => 'camp-youtube-video',
                'name' => 'YouTube - Video Action Marathon Launch',
                'type' => 'VIDEO',
                'budget' => 80.00,
                'adGroups' => [
                    [
                        'pId' => 'ag-video-runners',
                        'name' => 'Runners 18-45 Video Audience',
                        'ads' => [
                            ['pId' => 'ad-video-spot-1', 'name' => 'Marathon Launch Video (15s In-Stream)'],
                        ]
                    ]
                ]
            ]
        ];

        foreach ($demoCampaigns as $cDef) {
            $gCpId = $this->conn->fetchOne("SELECT id FROM campaigns WHERE campaign_id = ?", [$cDef['pId']]);
            if (!$gCpId) {
                $this->conn->insert('campaigns', [
                    'campaign_id' => $cDef['pId'],
                    'name' => $cDef['name'],
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
                $gCpId = $this->conn->lastInsertId();
            }
            $gCpId = (int)$gCpId;

            $ccId = $this->conn->fetchOne(
                "SELECT id FROM channeled_campaigns WHERE channel = ? AND platform_id = ? AND channeled_account_id = ?",
                [$channelId, $cDef['pId'], $caId]
            );
            if (!$ccId) {
                $this->conn->insert('channeled_campaigns', [
                    'channel' => $channelId,
                    'platform_id' => $cDef['pId'],
                    'campaign_id' => $gCpId,
                    'channeled_account_id' => $caId,
                    'budget' => $cDef['budget'],
                    'status' => 'ENABLED',
                    'type' => $cDef['type'],
                    'data' => json_encode(['advertising_channel_type' => $cDef['type']]),
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
                $ccId = $this->conn->lastInsertId();
            }
            $ccId = (int)$ccId;

            foreach ($cDef['adGroups'] as $agDef) {
                $cagId = $this->conn->fetchOne(
                    "SELECT id FROM channeled_ad_groups WHERE channel = ? AND platform_id = ? AND channeled_campaign_id = ?",
                    [$channelId, $agDef['pId'], $ccId]
                );
                if (!$cagId) {
                    $this->conn->insert('channeled_ad_groups', [
                        'channel' => $channelId,
                        'platform_id' => $agDef['pId'],
                        'channeled_campaign_id' => $ccId,
                        'channeled_account_id' => $caId,
                        'name' => $agDef['name'],
                        'status' => 'ENABLED',
                        'data' => json_encode([]),
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                    $cagId = $this->conn->lastInsertId();
                }
                $cagId = (int)$cagId;

                foreach ($agDef['ads'] as $adDef) {
                    $cadId = $this->conn->fetchOne(
                        "SELECT id FROM channeled_ads WHERE channel = ? AND platform_id = ? AND channeled_ad_group_id = ?",
                        [$channelId, $adDef['pId'], $cagId]
                    );
                    if (!$cadId) {
                        $this->conn->insert('channeled_ads', [
                            'channel' => $channelId,
                            'platform_id' => $adDef['pId'],
                            'channeled_account_id' => $caId,
                            'channeled_campaign_id' => $ccId,
                            'channeled_ad_group_id' => $cagId,
                            'name' => $adDef['name'],
                            'status' => 'ENABLED',
                            'data' => json_encode([]),
                            'created_at' => $now,
                            'updated_at' => $now
                        ]);
                    }
                }
            }
        }
    }
}
