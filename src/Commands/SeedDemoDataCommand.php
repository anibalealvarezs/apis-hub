<?php

namespace Commands;

use DateTime;
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
use Entities\Analytics\Query;
use Enums\Account as AccountType;
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceType;
use Enums\Period;
use Faker\Factory;
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
class SeedDemoDataCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private $faker;
    private Connection $conn;
    private array $bufferConfigs = [];
    private const BULK_SIZE = 10000;
    
    private array $ages = ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
    private array $genders = ['Female', 'Male', 'Unknown'];
    private array $dimensionSetCache = [];

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
        $allChannels = ['google_search_console', 'facebook_marketing', 'facebook_organic'];
        $channelsInput = $input->getOption('channels');
        $channels = $channelsInput ? explode(',', $channelsInput) : $allChannels;
        $isFresh = $input->getOption('fresh') || !$channelsInput;

        $output->writeln("<info>🚀 Seeding Realistic Demo Data...</info>");
        if ($isFresh) {
            $output->writeln("<comment>⚠️ Performing full database wipe...</comment>");
        } else {
            $output->writeln("<comment>📝 Performing partial update for channels: " . implode(', ', $channels) . "</comment>");
        }

        // --- 🧹 CLEAR REDIS CACHE ---
        try {
            $output->writeln("🧹 Flushing Redis cache...");
            \Helpers\Helpers::getRedisClient()->flushdb();
        } catch (\Exception $e) {
            $output->writeln("<comment>⚠️ Redis Flush skipped: " . $e->getMessage() . "</comment>");
        }

        ini_set('memory_limit', '4G');
        set_time_limit(0);

        $isPostgres = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
        
        if ($isPostgres) {
            $this->conn->executeStatement("SET session_replication_role = 'replica'");
        } else {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }

        if ($isFresh) {
            $tables = ['channeled_metrics', 'metrics', 'metric_configs', 'dimension_set_items', 'dimension_sets', 'dimension_values', 'dimension_keys', 'channeled_ads', 'channeled_ad_groups', 'channeled_campaigns', 'campaigns', 'channeled_accounts', 'accounts', 'pages', 'queries', 'posts', 'countries', 'devices'];
            foreach ($tables as $table) { 
                $truncateSql = $isPostgres ? "TRUNCATE TABLE $table RESTART IDENTITY CASCADE" : "TRUNCATE TABLE $table";
                $this->conn->executeStatement($truncateSql); 
            }
        } else {
            foreach ($channels as $chanName) {
                $chan = Channel::tryFromName($chanName);
                if (!$chan) {
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
                
                // Optional: Clean up posts related to these channels
                $this->conn->executeStatement("DELETE FROM posts WHERE channeled_account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)", [$chanId]);
            }
        }

        $this->seedBasicEntities();
        $this->seedDimensionHierarchy($output);

        if (in_array('google_search_console', $channels)) { $this->seedGscData($output); }
        if (in_array('facebook_marketing', $channels)) { $this->seedFacebookMarketingRealistic($output); }
        if (in_array('facebook_organic', $channels)) { $this->seedFacebookOrganicData($output); }

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
            if ($existing) continue;
            
            $country = new Country(); $country->addCode($c)->addName($c->getFullName()); $this->entityManager->persist($country);
        }
        $this->entityManager->flush();
    }

    private function seedDimensionHierarchy(OutputInterface $output): void
    {
        $output->writeln("🛠️ Ensuring Dimensions...");
        
        // Age Key
        $ageK = $this->conn->fetchOne("SELECT id FROM dimension_keys WHERE name = 'age'");
        if (!$ageK) {
            $this->conn->insert('dimension_keys', ['name' => 'age', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $ageK = $this->conn->lastInsertId();
        }
        
        // Gender Key
        $genK = $this->conn->fetchOne("SELECT id FROM dimension_keys WHERE name = 'gender'");
        if (!$genK) {
            $this->conn->insert('dimension_keys', ['name' => 'gender', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $genK = $this->conn->lastInsertId();
        }
        
        $ageValIds = [];
        foreach ($this->ages as $age) {
            $id = $this->conn->fetchOne("SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?", [$ageK, $age]);
            if (!$id) {
                $this->conn->insert('dimension_values', ['dimension_key_id' => $ageK, 'value' => $age, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $id = $this->conn->lastInsertId();
            }
            $ageValIds[$age] = $id;
        }
        
        $genValIds = [];
        foreach ($this->genders as $gen) {
            $id = $this->conn->fetchOne("SELECT id FROM dimension_values WHERE dimension_key_id = ? AND value = ?", [$genK, $gen]);
            if (!$id) {
                $this->conn->insert('dimension_values', ['dimension_key_id' => $genK, 'value' => $gen, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $id = $this->conn->lastInsertId();
            }
            $genValIds[$gen] = $id;
        }
        
        foreach ($this->ages as $age) {
            foreach ($this->genders as $gen) {
                $h = md5("age:{$age}|gender:{$gen}");
                $setId = $this->conn->fetchOne("SELECT id FROM dimension_sets WHERE hash = ?", [$h]);
                if (!$setId) {
                    $this->conn->insert('dimension_sets', ['hash' => $h, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    $setId = $this->conn->lastInsertId();
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $ageValIds[$age]]);
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $genValIds[$gen]]);
                }
                $this->dimensionSetCache["$age|$gen"] = $setId;
            }
        }
    }

    private function seedGscData(OutputInterface $output): void
    {
        $output->writeln("🔍 GSC seeding...");
        $pages = [];
        for ($i = 0; $i < 20; $i++) {
            $pId = "GSC-P$i";
            $page = $this->entityManager->getRepository(Page::class)->findOneBy(['platformId' => $pId]);
            if (!$page) {
                $page = new Page();
                $page->addUrl("https://demo.site/p$i")
                    ->addTitle("GSC Demo Page $i")
                    ->addHostname("demo.site")
                    ->addPlatformId($pId);
                $this->entityManager->persist($page);
                $this->entityManager->flush();
            }
            $pages[] = $page;
        }
        foreach ($this->getDates(60) as $date) {
            foreach ($pages as $p) { 
                $this->queueMetric(
                    channel: Channel::google_search_console, 
                    name: 'clicks', 
                    date: $date, 
                    value: rand(10, 100), 
                    pageId: $p->getId(),
                    pageUrl: $p->getUrl()
                ); 
            }
        }
        $this->flushAll();
    }

    private function seedFacebookMarketingRealistic(OutputInterface $output): void
    {
        $output->writeln("📊 FB Marketing (30 Acc, Core Breakdown Hierarchy)...");
        $fbParent = new Account(); 
        $gAccName = "Client " . $this->faker->company();
        $fbParent->addName($gAccName); 
        $this->entityManager->persist($fbParent); 
        $this->entityManager->flush();
        $gId = $fbParent->getId();
        $dates = $this->getDates(60); 
        $fbChan = Channel::facebook_marketing;
        $progressBar = new ProgressBar($output, 30); 
        $progressBar->start();
        for ($i = 0; $i < 30; $i++) {
            $caPId = $this->generatePlatformId();
            $ca = new ChanneledAccount(); 
            $ca->addPlatformId($caPId)
                ->addAccount($fbParent)
                ->addType(AccountType::META_AD_ACCOUNT)
                ->addChannel($fbChan->value)
                ->addName($this->faker->company());
            $this->entityManager->persist($ca); 
            $this->entityManager->flush();
            $caId = $ca->getId();

            $numC = rand(3, 5);
            for ($c = 0; $c < $numC; $c++) {
                $gCpPId = $this->generatePlatformId();
                $campG = new Campaign(); 
                $campG->addCampaignId($gCpPId)->addName($this->faker->catchPhrase()); 
                $this->entityManager->persist($campG);
                
                $cpPId = $gCpPId; // Same for Meta usually
                $cp = new ChanneledCampaign(); 
                $cp->addPlatformId($cpPId)->addChanneledAccount($ca)->addCampaign($campG)->addChannel($fbChan->value)->addBudget(rand(100, 500));
                $this->entityManager->persist($cp); 
                $this->entityManager->flush();
                $cpId = $cp->getId();

                for ($s = 0; $s < rand(1, 2); $s++) {
                    $agPId = $this->generatePlatformId();
                    $ag = new ChanneledAdGroup(); 
                    $ag->addPlatformId($agPId)->addChanneledAccount($ca)->addChannel($fbChan->value)->addName("AdSet: " . $this->faker->words(3, true))->addChanneledCampaign($cp);
                    $this->entityManager->persist($ag); 
                    $this->entityManager->flush();
                    $agId = $ag->getId();

                    for ($a = 0; $a < rand(1, 2); $a++) {
                        $adPId = $this->generatePlatformId();
                        $ad = new ChanneledAd(); 
                        $ad->addPlatformId($adPId)->addChanneledAccount($ca)->addChannel($fbChan->value)->addName("Ad: " . $this->faker->words(2, true))->addChanneledAdGroup($ag);
                        $this->entityManager->persist($ad); 
                        $this->entityManager->flush();
                        $this->seedRealisticAdDaily($dates, $gId, $caId, $campG->getId(), $cpId, $agId, $ad->getId(), $gAccName, $caPId, $gCpPId, $cpPId, $agPId, $adPId);
                    }
                }
            }
            $progressBar->advance(); 
            $this->entityManager->clear();
            $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['id' => $gId]);
            if (!$fbParent) {
                $fbParent = new Account(); 
                $fbParent->addName($gAccName); 
                $this->entityManager->persist($fbParent); 
                $this->entityManager->flush();
                $gId = $fbParent->getId();
            }
        }
        $progressBar->finish(); $output->writeln("");
    }

    private function seedRealisticAdDaily($dates, $gId, $caId, $gCpId, $cpId, $agId, $adId, $accName, $caPId, $gCpPId, $cpPId, $agPId, $adPId): void
    {
        $fbChan = Channel::facebook_marketing;
        foreach ($dates as $date) {
            $used = [];
            for ($b = 0; $b < rand(2, 4); $b++) {
                $age = $this->ages[array_rand($this->ages)]; $gen = $this->genders[array_rand($this->genders)];
                if (isset($used["$age|$gen"])) continue; $used["$age|$gen"] = true;
                $setId = $this->dimensionSetCache["$age|$gen"];
                $imps = rand(1, 1000); $spend = (float)(($imps / 1000) * rand(2, 20)); $reach = (float)($imps * (rand(700, 950) / 1000)); $clicks = (int)($imps * (rand(0, 100) / 1000)); $results = (int)($clicks * (rand(0, 1000) / 1000));
                $data = ['impressions' => $imps, 'spend' => $spend, 'reach' => $reach, 'frequency' => $imps/($reach?:1), 'clicks' => $clicks, 'ctr' => $clicks/($imps?:1), 'cpc' => $spend/($clicks?:1), 'cpm' => ($spend/($imps?:1))*1000, 'results' => $results, 'cost_of_result' => $spend/($results?:1), 'results_rate' => $results/($imps?:1), 'actions' => (float)($clicks*rand(75, 125)/100)];
                foreach ($data as $name => $val) { 
                    $this->queueMetric(
                        channel: $fbChan, 
                        name: $name, 
                        date: $date, 
                        value: $val, 
                        setId: $setId, 
                        caId: $caId, 
                        gAccId: $gId, 
                        gCpId: $gCpId, 
                        cpId: $cpId, 
                        agId: $agId, 
                        adId: $adId,
                        // Signature inputs
                        accName: $accName,
                        caPId: $caPId,
                        gCpPId: $gCpPId,
                        cpPId: $cpPId,
                        agPId: $agPId,
                        adPId: $adPId
                    ); 
                }
            }
        }
    }

    private function seedFacebookOrganicData(OutputInterface $output): void
    {
        $output->writeln("📱 FB Organic & IG Business (30 Pages/Accounts, Full Depth)...");
        
        $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => "Client Demo (FB)"]);
        if (!$fbParent) {
            $fbParent = new Account();
            $fbParent->addName("Client Demo (FB)");
            $this->entityManager->persist($fbParent);
            $this->entityManager->flush();
        }
        $gId = $fbParent->getId();
        $gAccName = $fbParent->getName();
        
        $dates = $this->getDates(365);
        $fbChan = Channel::facebook_organic;
        $igMediaTypes = ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM', 'REEL'];
        
        $progressBar = new ProgressBar($output, 3);
        $progressBar->start();

        for ($i = 0; $i < 3; $i++) {
            $namePrefix = $this->faker->unique()->company();
            $pagePId = "FB-PAGE-" . md5($namePrefix);
            $pageUrl = "https://facebook.com/" . strtolower(str_replace(' ', '.', $namePrefix));
            
            // 1. Create/Find FB Page
            $page = $this->entityManager->getRepository(Page::class)->findOneBy(['platformId' => $pagePId]);
            if (!$page) {
                $page = new Page();
                $page->addPlatformId($pagePId)
                    ->addAccount($fbParent)
                    ->addTitle($namePrefix . " Official Page")
                    ->addUrl($pageUrl)
                    ->addHostname("facebook.com")
                    ->addData(['source' => 'facebook']);
                $this->entityManager->persist($page);
                $this->entityManager->flush();
            }
            $pId = $page->getId();

            // 2. Create/Find Linked IG Account
            $igPId = "IG-ACC-" . md5($namePrefix);
            $caIg = $this->entityManager->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $igPId, 'channel' => $fbChan->value]);
            if (!$caIg) {
                $caIg = new ChanneledAccount();
                $caIg->addPlatformId($igPId)
                    ->addAccount($fbParent)
                    ->addType(AccountType::INSTAGRAM)
                    ->addChannel($fbChan->value)
                    ->addName($namePrefix . " Instagram")
                    ->addData(['facebook_page_id' => $pagePId]);
                $this->entityManager->persist($caIg);
                $this->entityManager->flush();
            }
            $caIgId = $caIg->getId();

            // 3. Link IG to FB Page (Data metadata)
            $page->addData(array_merge($page->getData(), [
                'ig_account' => $igPId,
                'ig_account_name' => $caIg->getName()
            ]));
            $this->entityManager->persist($page);
            $this->entityManager->flush();

            // 4. Generate FB Page Daily Metrics (365 days)
            $this->seedDailyMetrics($dates, $fbChan, [
                'page_impressions' => [1000, 5000],
                'page_post_engagements' => [50, 200],
                'page_views_total' => [20, 100],
                'page_fans' => [5000, 15000, 'trend']
            ], $gId, null, null, null, null, $pId, $gAccName, null, $pageUrl);

            // 5. Generate IG Account Daily Metrics (365 days)
            $this->seedDailyMetrics($dates, $fbChan, [
                'impressions' => [2000, 10000],
                'reach' => [1500, 8000],
                'profile_views' => [10, 50],
                'follower_count' => [1000, 5000, 'trend']
            ], $gId, $caIgId, null, null, null, $pId, $gAccName, $igPId, $pageUrl);

            // 6. Generate FB Posts (deterministic IDs for idempotency)
            $numFbPosts = 50; 
            for ($p = 0; $p < $numFbPosts; $p++) {
                $postPId = "FB-POST-" . md5($namePrefix . $p);
                $postCreated = $dates[array_rand($dates)];
                
                $postObj = $this->entityManager->getRepository(\Entities\Analytics\Post::class)->findOneBy(['postId' => $postPId, 'page' => $page]);
                if (!$postObj) {
                    $postObj = new \Entities\Analytics\Post();
                    $postObj->addPostId($postPId)
                        ->addAccount($fbParent)
                        ->addPage($page)
                        ->addData([
                            'message' => $this->faker->paragraph(),
                            'created_time' => $postCreated . 'T' . rand(10, 20) . ':00:00+0000',
                            'permalink_url' => $pageUrl . "/posts/" . $postPId
                        ]);
                    $this->entityManager->persist($postObj);
                    $this->entityManager->flush();
                }
                
                // Metrics for this post from creation date
                $postDates = array_filter($dates, fn($d) => $d >= $postCreated);
                $this->seedDailyMetrics($postDates, $fbChan, [
                    'post_impressions' => [100, 500],
                    'post_engagement' => [5, 30],
                    'post_reactions_by_type_total' => [2, 15]
                ], $gId, null, null, null, $postObj->getId(), $pId, $gAccName, null, $pageUrl, $postPId);
            }

            // 7. Generate IG Media (deterministic IDs for idempotency)
            $numIgMedia = 100;
            for ($m = 0; $m < $numIgMedia; $m++) {
                $mediaPId = "IG-MEDIA-" . md5($namePrefix . $m);
                $mediaCreated = $dates[array_rand($dates)];
                $mediaType = $igMediaTypes[array_rand($igMediaTypes)];
                
                $postIg = $this->entityManager->getRepository(\Entities\Analytics\Post::class)->findOneBy(['postId' => $mediaPId, 'channeledAccount' => $caIg]);
                if (!$postIg) {
                    $postIg = new \Entities\Analytics\Post();
                    $postIg->addPostId($mediaPId)
                        ->addAccount($fbParent)
                        ->addPage($page)
                        ->addChanneledAccount($caIg)
                        ->addData([
                            'caption' => $this->faker->paragraph(),
                            'media_type' => $mediaType,
                            'timestamp' => $mediaCreated . 'T' . rand(10, 20) . ':00:00+0000',
                            'permalink' => "https://instagram.com/p/" . $mediaPId
                        ]);
                    $this->entityManager->persist($postIg);
                    $this->entityManager->flush();
                }

                // Metrics for this media from creation date
                $mediaDates = array_filter($dates, fn($d) => $d >= $mediaCreated);
                $igMetrics = [
                    'reach' => [500, 2000],
                    'impressions' => [600, 2500],
                    'total_interactions' => [20, 100],
                    'likes' => [15, 80],
                    'comments' => [2, 10],
                    'shares' => [1, 5],
                    'saves' => [1, 10]
                ];
                if ($mediaType === 'VIDEO' || $mediaType === 'REEL') {
                    $igMetrics['plays'] = [1000, 5000];
                }
                
                $this->seedDailyMetrics($mediaDates, $fbChan, $igMetrics, $gId, $caIgId, null, null, $postIg->getId(), $pId, $gAccName, $igPId, $pageUrl, $mediaPId);
            }

            $progressBar->advance();
            $this->entityManager->clear();
            $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['id' => $gId]);
        }
        $progressBar->finish();
        $output->writeln("");
    }

    private function seedDailyMetrics(array $dates, Channel $channel, array $metricDefs, int $gAccId, ?int $caId = null, ?int $gCpId = null, ?int $cpId = null, ?int $postId = null, ?int $pageId = null, ?string $accName = null, ?string $caPId = null, ?string $pageUrl = null, ?string $postPId = null): void
    {
        $trendValues = [];
        foreach ($dates as $date) {
            foreach ($metricDefs as $name => $range) {
                $min = $range[0];
                $max = $range[1];
                $isTrend = isset($range[2]) && $range[2] === 'trend';
                
                if ($isTrend) {
                    if (!isset($trendValues[$name])) $trendValues[$name] = $min;
                    $trendValues[$name] += rand(-5, 15);
                    if ($trendValues[$name] < $min) $trendValues[$name] = $min;
                    $val = $trendValues[$name];
                } else {
                    $val = rand($min, $max);
                }
                
                $this->queueMetric(
                    channel: $channel, 
                    name: $name, 
                    date: $date, 
                    value: $val, 
                    setId: null, 
                    pageId: $pageId, 
                    adId: null, 
                    agId: null, 
                    cpId: $cpId, 
                    caId: $caId, 
                    gAccId: $gAccId, 
                    gCpId: $gCpId, 
                    postId: $postId,
                    accName: $accName,
                    caPId: $caPId,
                    pageUrl: $pageUrl,
                    postPId: $postPId
                );
            }
        }
    }

    private function getDates($days): array
    {
        $dates = []; $d = new DateTime("-$days days");
        for ($i = 0; $i < $days; $i++) { $dates[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
        return $dates;
    }

    private function queueMetric(
        Channel $channel, $name, $date, $value, $setId = null, $pageId = null, $adId = null, $agId = null, $cpId = null, $caId = null, $gAccId = null, $gCpId = null, $postId = null,
        ?string $accName = null, ?string $caPId = null, ?string $gCpPId = null, ?string $cpPId = null, ?string $agPId = null, ?string $adPId = null, ?string $pageUrl = null, ?string $postPId = null
    ): void {
        $sig = \Classes\KeyGenerator::generateMetricConfigKey(
            channel: $channel,
            name: $name,
            period: 'daily',
            metricDate: $date,
            account: $accName,
            channeledAccount: $caPId,
            campaign: $gCpPId,
            channeledCampaign: $cpPId,
            channeledAdGroup: $agPId,
            channeledAd: $adPId,
            page: $pageUrl,
            post: $postPId
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
            'config_signature' => $sig, 
            'value' => (float)$value, 
            'dimension_set_id' => $setId
        ];
        if (count($this->bufferConfigs) >= self::BULK_SIZE) { $this->flushAll(); }
    }

    private function flushAll(): void
    {
        if (empty($this->bufferConfigs)) return;
        $now = date('Y-m-d H:i:s');
        $configsToInsert = array_map(function($row) use ($now) { 
            return [
                'channel' => $row['channel'], 
                'name' => $row['name'], 
                'period' => 'daily', 
                'metric_date' => $row['metric_date'], 
                'page_id' => $row['page_id'], 
                'post_id' => $row['post_id'],
                'channeled_ad_id' => $row['channeled_ad_id'], 
                'channeled_ad_group_id' => $row['channeled_ad_group_id'], 
                'channeled_campaign_id' => $row['channeled_campaign_id'], 
                'campaign_id' => $row['campaign_id'], 
                'channeled_account_id' => $row['channeled_account_id'], 
                'account_id' => $row['account_id'], 
                'config_signature' => $row['config_signature'], 
                'created_at' => $now, 
                'updated_at' => $now
            ]; 
        }, $this->bufferConfigs);
        
        $this->bulkInsert('metric_configs', $configsToInsert);
        
        $quotedSigs = array_map(fn($c) => $this->conn->quote($c['config_signature']), $this->bufferConfigs);
        $idMap = $this->conn->fetchAllKeyValue("SELECT config_signature, id FROM metric_configs WHERE config_signature IN (" . implode(',', $quotedSigs) . ")");
        
        $metricsToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $cfgId = $idMap[$c['config_signature']] ?? null;
            if ($cfgId) $metricsToInsert[] = [
                'metric_config_id' => $cfgId, 
                'dimensions_hash' => md5((string)$c['dimension_set_id']), 
                'value' => $c['value'], 
                'created_at' => $now, 
                'updated_at' => $now
            ];
        }
        $this->bulkInsert('metrics', $metricsToInsert);
        
        $quotedConfigIds = array_map('intval', array_values($idMap));
        if (empty($quotedConfigIds)) return;
        
        $mMap = $this->conn->fetchAllKeyValue("SELECT metric_config_id, id FROM metrics WHERE metric_config_id IN (" . implode(',', $quotedConfigIds) . ")");
        
        $chanToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $mId = $mMap[$idMap[$c['config_signature']] ?? 0] ?? null;
            if ($mId) $chanToInsert[] = [
                'platform_id' => $this->generatePlatformId(), 
                'channel' => $c['channel'], 
                'metric_id' => $mId, 
                'dimension_set_id' => $c['dimension_set_id'], 
                'platform_created_at' => $c['metric_date'], 
                'created_at' => $now, 
                'updated_at' => $now
            ];
        }
        $this->bulkInsert('channeled_metrics', $chanToInsert);
        $this->bufferConfigs = [];
    }

    private function bulkInsert(string $table, array $rows): void
    {
        if (empty($rows)) return;
        $columns = array_keys($rows[0]);
        $vals = [];
        foreach ($rows as $row) { $vals[] = "(" . implode(',', array_map(fn($v) => is_null($v) ? 'NULL' : $this->conn->quote((string)$v), array_values($row))) . ")"; }
        
        $isPostgres = $this->conn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
        $insertSql = $isPostgres ? "INSERT INTO $table (" . implode(',', $columns) . ") VALUES " . implode(',', $vals) . " ON CONFLICT DO NOTHING" : "INSERT IGNORE INTO $table (" . implode(',', $columns) . ") VALUES " . implode(',', $vals);
        
        $this->conn->executeStatement($insertSql);
    }
}
