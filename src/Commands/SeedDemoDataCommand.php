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
use Entities\Analytics\Post;
use Entities\Analytics\Query;
use Enums\Account as AccountType;
use Enums\Channel;
use Enums\Country as CountryEnum;
use Enums\Device as DeviceType;
use Enums\Period;
use Faker\Factory;
use Anibalealvarezs\ApiSkeleton\Classes\KeyGenerator;
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
    private const BULK_SIZE = 2000;
    
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
                $this->conn->executeStatement("DELETE FROM posts WHERE channeled_account_id IN (SELECT id FROM channeled_accounts WHERE channel = ?)", [$chanId]);
            }
        }

        $output->writeln("🛠️ Ensuring Basic Entities (Countries)...");
        $this->seedBasicEntities();
        
        $output->writeln("🛠️ Ensuring Dimension Hierarchy...");
        $this->seedDimensionHierarchy($output);

        if (in_array('google_search_console', $channels)) { $this->seedGscData($output); }
        if (in_array('facebook_marketing', $channels)) { $this->seedFacebookMarketingRealistic($output); }
        if (in_array('facebook_organic', $channels)) { $this->seedFacebookOrganicDataUnified($output); }

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
                $dimensions = [
                    ['dimensionKey' => 'age', 'dimensionValue' => $age],
                    ['dimensionKey' => 'gender', 'dimensionValue' => $gen]
                ];
                $h = KeyGenerator::generateDimensionsHash($dimensions);
                $setId = $this->conn->fetchOne("SELECT id FROM dimension_sets WHERE hash = ?", [$h]);
                if (!$setId) {
                    $this->conn->insert('dimension_sets', ['hash' => $h, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    $setId = $this->conn->lastInsertId();
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $ageValIds[$age]]);
                    $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $genValIds[$gen]]);
                }
                $this->dimensionSetCache["$age|$gen"] = ['id' => $setId, 'hash' => $h];
            }
        }
    }

    private function seedGscData(OutputInterface $output): void
    {
        $output->writeln("🔍 GSC (10 Sites, 6 Months, Correct Universal SEO Domain Model)...");
        
        $gscChan = Channel::google_search_console;
        $dates = $this->getDates(180); 
        $countryEnumValues = ['USA', 'ESP', 'MEX', 'COL'];
        $deviceEnumValues = ['desktop', 'mobile', 'tablet'];
        $appearances = ['AMP_TOP_STORIES', 'PRODUCT_SNIPPETS', 'REVIEW_SNIPPET', 'VIDEO', 'ORGANIC_SHOPPING'];
        
        $dimManager = new \Classes\DimensionManager($this->entityManager);

        // Pre-fetch Universal Entities
        $countries = [];
        foreach ($countryEnumValues as $code) {
            $enum = CountryEnum::from($code);
            $c = $this->entityManager->getRepository(Country::class)->findOneBy(['code' => $enum]);
            if (!$c) {
                $c = (new Country())->addCode($enum)->addName($code);
                $this->entityManager->persist($c);
            }
            $countries[$code] = $c;
        }
        $devices = [];
        foreach ($deviceEnumValues as $type) {
            $enum = DeviceType::from($type);
            $d = $this->entityManager->getRepository(Device::class)->findOneBy(['type' => $enum]);
            if (!$d) {
                $d = (new Device())->addType($enum);
                $this->entityManager->persist($d);
            }
            $devices[$type] = $d;
        }
        $this->entityManager->flush();

        for ($s = 1; $s <= 10; $s++) {
            $hostname = "blog" . $s . ".demo-agency.com";
            $siteName = "Brand Blog $s ($hostname)";
            
            $property = $this->entityManager->getRepository(Page::class)->findOneBy(['platformId' => $hostname]);
            if (!$property) {
                $property = (new Page())->addUrl("https://$hostname")->addTitle($siteName)->addHostname($hostname)->addPlatformId($hostname)->addCanonicalId($hostname);
                $this->entityManager->persist($property);
                $this->entityManager->flush();
            }

            $childUrls = [];
            for($i=0; $i<20; $i++) $childUrls[] = "https://$hostname/article-" . $this->faker->slug();
            
            $queries = [];
            for($i=0; $i<30; $i++) $queries[] = $this->faker->words(rand(1, 4), true);

            foreach ($dates as $date) {
                for ($j = 0; $j < 8; $j++) {
                    $url = $childUrls[array_rand($childUrls)];
                    $qStr = $queries[array_rand($queries)];
                    $code = $countryEnumValues[array_rand($countryEnumValues)];
                    $type = $deviceEnumValues[array_rand($deviceEnumValues)];
                    
                    $country = $countries[$code]; $device = $devices[$type];
                    $appearance = $appearances[array_rand($appearances)];

                    // Resolve Dimensions (Universal values are OUT, but Page and Query are dimensions!)
                    $dimensionSet = $dimManager->resolveDimensionSet([
                        ['dimensionKey' => 'page', 'dimensionValue' => $url],
                        ['dimensionKey' => 'query', 'dimensionValue' => $qStr],
                        ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $appearance]
                    ]);
                    $setId = $dimensionSet->getId();

                    $imps = rand(10, 200); $clicks = (int)($imps * rand(1, 10) / 100); $pos = (float)rand(10, 80) / 10;
                    $data = ['impressions' => $imps, 'clicks' => $clicks, 'ctr' => $imps > 0 ? $clicks / $imps : 0, 'position' => $pos, 'keys' => [$url, $qStr, $code, $type, $appearance]];

                    foreach (['impressions', 'clicks', 'ctr', 'position'] as $name) {
                        $this->queueMetric(
                            channel: $gscChan, name: $name, date: $date, value: $data[$name], 
                            setId: $setId,
                            pageId: $property->getId(), 
                            queryId: null, // Strategic Only
                            countryId: $country->getId(),
                            deviceId: $device->getId(),
                            postId: null,
                            data: json_encode($data),
                            pageUrl: $property->getUrl(),
                            queryPId: null,
                            countryPId: $code,
                            devicePId: $type,
                            setHash: $dimensionSet->getHash()
                        );
                    }
                }
            }
            $this->entityManager->clear(); 
            $dimManager->clearCaches();
            $output->writeln("   - Site $hostname complete.");
        }
        $this->flushAll();
    }

    private function seedFacebookMarketingRealistic(OutputInterface $output): void
    {
        $output->writeln("📊 FB Marketing (Massive Simulation, JSON Source Logic)...");
        
        $fbChan = Channel::facebook_marketing;
        $accCount = 30; // 30 Ad Accounts as requested
        $dates = $this->getDates(30); 
        $statuses = ['ACTIVE', 'PAUSED', 'ARCHIVED'];
        $objectives = ['OUTCOME_SALES', 'OUTCOME_AWARENESS', 'OUTCOME_LEADS', 'OUTCOME_TRAFFIC'];

        // Parent Account (Client)
        $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => "Marketing Demo Client"]);
        if (!$fbParent) {
            $fbParent = (new Account())->addName("Marketing Demo Client");
            $this->entityManager->persist($fbParent); 
            $this->entityManager->flush();
        }
        $gId = $fbParent->getId();

        $progress = new ProgressBar($output, $accCount);
        $progress->start();

        for ($i = 0; $i < $accCount; $i++) {
            // 1. Channeled Ad Account
            $caPId = 'act_' . $this->generatePlatformId();
            $ca = (new ChanneledAccount())
                ->addPlatformId($caPId)
                ->addAccount($fbParent)
                ->addType(AccountType::META_AD_ACCOUNT)
                ->addChannel($fbChan->value)
                ->addName($this->faker->company())
                ->addData([
                    'id' => $caPId,
                    'account_status' => 1,
                    'currency' => 'USD',
                    'timezone_name' => 'America/New_York',
                    'business_name' => $this->faker->company()
                ]);
            $this->entityManager->persist($ca); 
            $this->entityManager->flush();

            // 2. Campaigns
            $campCount = rand(5, 10);
            for ($c = 0; $c < $campCount; $c++) {
                $gCpPId = $this->generatePlatformId();
                $campG = (new Campaign())->addCampaignId($gCpPId)->addName($this->faker->catchPhrase()); 
                $this->entityManager->persist($campG);
                
                $cp = (new ChanneledCampaign())
                    ->addPlatformId($gCpPId)
                    ->addChanneledAccount($ca)
                    ->addCampaign($campG)
                    ->addChannel($fbChan->value)
                    ->addBudget(rand(100, 500))
                    ->addData([
                        'id' => $gCpPId,
                        'name' => $campG->getName(),
                        'objective' => $objectives[array_rand($objectives)],
                        'status' => $statuses[array_rand($statuses)],
                        'buying_type' => 'AUCTION',
                        'daily_budget' => rand(5000, 20000) // cents
                    ]);
                $this->entityManager->persist($cp);
                $this->entityManager->flush();

                // 3. AdSets
                $agCount = rand(2, 4);
                for ($s = 0; $s < $agCount; $s++) {
                    $agPId = $this->generatePlatformId();
                    $agName = "AdSet: " . $this->faker->words(3, true);
                    $ag = (new ChanneledAdGroup())
                        ->addPlatformId($agPId)
                        ->addChanneledAccount($ca)
                        ->addChannel($fbChan->value)
                        ->addName($agName)
                        ->addChanneledCampaign($cp)
                        ->addData([
                            'id' => $agPId,
                            'name' => $agName,
                            'status' => $statuses[array_rand($statuses)],
                            'billing_event' => 'IMPRESSIONS',
                            'optimization_goal' => 'REACH',
                            'targeting' => ['geo_locations' => ['countries' => ['US']]]
                        ]);
                    $this->entityManager->persist($ag);
                    $this->entityManager->flush();

                    // 4. Ads
                    $adCount = rand(2, 5);
                    for ($a = 0; $a < $adCount; $a++) {
                        $adPId = $this->generatePlatformId();
                        $adName = "Ad: " . $this->faker->words(2, true);
                        $ad = (new ChanneledAd())
                            ->addPlatformId($adPId)
                            ->addChanneledAccount($ca)
                            ->addChannel($fbChan->value)
                            ->addName($adName)
                            ->addChanneledAdGroup($ag)
                            ->addData([
                                'id' => $adPId,
                                'name' => $adName,
                                'status' => $statuses[array_rand($statuses)],
                                'creative' => ['id' => 'cre_' . rand(1000, 9999)],
                                'preview_shareable_link' => "https://fb.com/ads/preview/$adPId"
                            ]);
                        $this->entityManager->persist($ad);
                        $this->entityManager->flush();

                        // 5. Metrics (Daily Insights Simulation)
                        $this->seedRealisticAdDaily($dates, $gId, $ca->getId(), $campG->getId(), $cp->getId(), $ag->getId(), $ad->getId(), $fbParent->getName(), $caPId, $gCpPId, $gCpPId, $agPId, $adPId);
                    }
                }
            }
            $progress->advance();
            $this->entityManager->clear();
            $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['id' => $gId]);
        }
        $progress->finish(); $output->writeln("");
    }

    private function seedRealisticAdDaily($dates, $gId, $caId, $gCpId, $cpId, $agId, $adId, $accName, $caPId, $gCpPId, $cpPId, $agPId, $adPId): void
    {
        $fbChan = Channel::facebook_marketing;
        foreach ($dates as $date) {
            $used = [];
            for ($b = 0; $b < rand(1, 2); $b++) { // Reduced dimensions for performance
                $age = $this->ages[array_rand($this->ages)]; $gen = $this->genders[array_rand($this->genders)];
                if (isset($used["$age|$gen"])) continue; $used["$age|$gen"] = true;
                $setInfo = $this->dimensionSetCache["$age|$gen"];
                $setId = $setInfo['id'];
                $setHash = $setInfo['hash'];
                
                $imps = rand(100, 2000); 
                $reach = (int)($imps * rand(70, 95) / 100);
                $spend = (float)($imps * rand(5, 15) / 1000);
                $clicks = (int)($imps * rand(1, 5) / 100);
                
                $data = [
                    'impressions' => $imps, 
                    'spend' => $spend, 
                    'reach' => $reach, 
                    'clicks' => $clicks,
                    'ctr' => $imps > 0 ? $clicks / $imps : 0,
                    'cpc' => $clicks > 0 ? $spend / $clicks : 0,
                    'results' => (int)($clicks * rand(5, 15) / 100)
                ];

                foreach ($data as $name => $val) { 
                    $this->queueMetric(
                        channel: $fbChan, 
                        name: $name, 
                        date: $date, 
                        value: $val, 
                        setId: $setId, 
                        setHash: $setHash,
                        caId: $caId, 
                        gAccId: $gId, 
                        gCpId: $gCpId, 
                        cpId: $cpId, 
                        agId: $agId, 
                        adId: $adId,
                        accName: $accName,
                        caPId: $caPId,
                        gCpPId: $gCpPId,
                        cpPId: $cpPId,
                        agPId: $agPId,
                        adPId: $adPId,
                        data: json_encode($data) // Here we store the "API Like" JSON source
                    ); 
                }
            }
        }
    }

    private function seedFacebookOrganicDataUnified(OutputInterface $output): void
    {
        $output->writeln("🚀 Seeding High-Volume Realistic Demo Data...");
        
        $fbChan = Channel::facebook_organic;
        $igMediaTypes = ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM', 'REEL'];
        $igProductTypes = ['FEED', 'REELS', 'STORY'];
        $dates = $this->getDates(30); 

        // 1. Ensure 3 Demo Pages & Accounts exist (Dimensions)
        $pagesToSeed = 3;
        $seededPages = [];
        for ($i = 1; $i <= $pagesToSeed; $i++) {
            $name = "Demo Brand $i";
            $fbAcc = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => "$name (FB)"]);
            if (!$fbAcc) {
                $fbAcc = (new Account())->addName("$name (FB)");
                $this->entityManager->persist($fbAcc);
            }
            
            $fbPId = "fb_page_$i";
            $page = $this->entityManager->getRepository(Page::class)->findOneBy(['platformId' => $fbPId]);
            if (!$page) {
                $page = (new Page())->addPlatformId($fbPId)->addAccount($fbAcc)->addTitle("$name FB Page")->addUrl("https://fb.com/$fbPId")->addCanonicalId($fbPId);
                $this->entityManager->persist($page);
            }

            $caFb = $this->entityManager->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $fbPId]);
            if (!$caFb) {
                $caFb = (new ChanneledAccount())->addPlatformId($fbPId)->addAccount($fbAcc)->addType(AccountType::FACEBOOK_PAGE)->addChannel($fbChan->value)->addName("$name FB Page");
                $this->entityManager->persist($caFb);
            }

            $igPId = "ig_acc_$i";
            $caIg = $this->entityManager->getRepository(ChanneledAccount::class)->findOneBy(['platformId' => $igPId]);
            if (!$caIg) {
                $caIg = (new ChanneledAccount())->addPlatformId($igPId)->addAccount($fbAcc)->addType(AccountType::INSTAGRAM)->addChannel($fbChan->value)->addName("$name IG Account");
                $caIg->addData(['instagram_id' => $igPId, 'facebook_page_id' => $fbPId]); // Store it for lookup
                $this->entityManager->persist($caIg);
            }
            $this->entityManager->flush();
            $seededPages[] = ['page' => $page, 'fbAcc' => $fbAcc, 'caIg' => $caIg, 'caFb' => $caFb];
        }

        $progress = new ProgressBar($output, count($seededPages));
        $progress->start();

        foreach ($seededPages as $data) {
            $page = $data['page'];
            $fbParent = $data['fbAcc'];
            $caIg = $data['caIg'];
            $caFb = $data['caFb'];

            // 1. Create Media Entities
            $mediaEntities = [];
            $currentLifetimeValues = [];
            $igMediaCount = rand(100, 200);
            
            $postParams = [];
            $now = date('Y-m-d H:i:s');
            
            for ($m = 0; $m < $igMediaCount; $m++) {
                $mediaPId = 'ig_media_' . $page->getId() . '_' . $m;
                $itemDate = $dates[array_rand($dates)];
                
                $postParams[] = [
                    'post_id' => $mediaPId,
                    'account_id' => $fbParent->getId(),
                    'page_id' => $page->getId(),
                    'channeled_account_id' => $caIg->getId(),
                    'data' => json_encode([
                        'id' => $mediaPId,
                        'caption' => $this->faker->sentence(),
                        'media_type' => $igMediaTypes[array_rand($igMediaTypes)],
                        'media_product_type' => $igProductTypes[array_rand($igProductTypes)],
                        'permalink' => "https://www.instagram.com/p/demo_" . $mediaPId,
                        'timestamp' => $itemDate . 'T07:00:00+0000'
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now
                ];

                $postIg = new Post();
                $postIg->addPostId($mediaPId)->addAccount($fbParent)->addPage($page)->addChanneledAccount($caIg);
                $mediaEntities[] = $postIg;
                
                $currentLifetimeValues[$mediaPId] = [
                    'reach' => rand(10, 50), 'impressions' => rand(15, 60), 'likes' => 0, 'comments' => 0,
                    'saved' => 0, 'shares' => 0, 'views' => 0, 'total_interactions' => 0
                ];
            }

            // 2. Create FB Post Entities
            $fbPostCount = rand(50, 100);
            for ($p = 0; $p < $fbPostCount; $p++) {
                $postPId = 'fb_post_' . $page->getId() . '_' . $p;
                $itemDate = $dates[array_rand($dates)];
                
                $postParams[] = [
                    'post_id' => $postPId,
                    'account_id' => $fbParent->getId(),
                    'page_id' => $page->getId(),
                    'channeled_account_id' => $caFb->getId(),
                    'data' => json_encode([
                        'id' => $postPId,
                        'message' => $this->faker->sentence(),
                        'created_time' => $itemDate . 'T07:00:00+0000',
                        'permalink_url' => "https://www.facebook.com/posts/demo_" . $postPId
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
            
            // Ultra-fast batch insert
            if (!empty($postParams)) {
                $output->writeln(" \n➤ Creating $fbPostCount FB posts and $igMediaCount IG Media items for '{$page->getTitle()}'...");
                $cols = array_keys($postParams[0]);
                
                $plat = $this->conn->getDatabasePlatform();
                $isP = str_contains(strtolower(get_class($plat)), 'postgre');
                $ignore = $isP ? "" : "IGNORE";
                $suffix = $isP ? " ON CONFLICT DO NOTHING" : "";
                
                $sql = "INSERT $ignore INTO posts (" . implode(', ', $cols) . ") VALUES ";
                
                $values = [];
                $params = [];
                foreach ($postParams as $row) {
                    $values[] = "(?, ?, ?, ?, ?, ?, ?)";
                    foreach ($row as $val) {
                        $params[] = $val;
                    }
                }
                $output->writeln("  - Inserting posts in DBAL batch...");
                $sql .= implode(', ', $values) . $suffix;
                $this->entityManager->getConnection()->executeStatement($sql, $params);
            }
            
            $output->writeln("  - Re-fetching post IDs from DB...");
            // Re-fetch posts for IDs
            $allPosts = $this->entityManager->getRepository(Post::class)->findBy(['page' => $page]);
            $fbPostEntities = [];
            $mediaMap = ['map' => []]; // We will populate this with real DB ids for IG media
            
            foreach ($allPosts as $pst) {
                if (str_starts_with($pst->getPostId(), 'fb_post_')) {
                    $fbPostEntities[] = $pst;
                } elseif (str_starts_with($pst->getPostId(), 'ig_media_')) {
                    $mediaMap['map'][$pst->getPostId()] = $pst->getId();
                    
                    // Update our IG Media objects with real IDs if needed
                    foreach ($mediaEntities as $mediaEntity) {
                        if ($mediaEntity->getPostId() === $pst->getPostId()) {
                            // Link them up
                        }
                    }
                }
            }

            $output->writeln("  - Processing Facebook Simulation (Page & Posts)...");
            // 3. FB Simulation
            $fbChan = Channel::facebook_organic;
            $gId = $fbParent->getId();
            $gAccName = $fbParent->getName();
            $pId = $page->getId();
            $pageUrl = $page->getUrl();
            $caFbId = $caFb->getId();
            
            $fbPageMetrics = [
                'page_fans' => [0, 10, 'trend'],
                'page_impressions' => [50, 500],
                'page_post_engagements' => [10, 100],
                'page_views_total' => [5, 40]
            ];
            $this->seedDailyMetrics($dates, $fbChan, $fbPageMetrics, $gId, $caFbId, null, null, null, $pId, $gAccName, (string)$caFb->getPlatformId(), null, null, null, $pageUrl, null);

            $fbPostMetrics = [
                'post_impressions' => [10, 100],
                'post_engagement' => [2, 20],
                'post_reactions_by_type_total' => [1, 10]
            ];
            foreach ($fbPostEntities as $fbPostEntity) {
                $this->seedDailyMetrics($dates, $fbChan, $fbPostMetrics, $gId, $caFbId, null, null, $fbPostEntity->getId(), $pId, $gAccName, (string)$caFb->getPlatformId(), null, null, null, $pageUrl, $fbPostEntity->getPostId());
            }

            $output->writeln("  - Starting 30-day Instagram Simulation loop...");
            // 4. IG Simulation Loop
            $allIgMediaMetrics = new \Doctrine\Common\Collections\ArrayCollection();
            $pageMap = ['map' => [$page->getPlatformId() => $page->getId()]];
            $accountMap = ['map' => [$fbParent->getName() => $fbParent->getId()], 'mapReverse' => [$fbParent->getId() => $fbParent->getName()]];
            $channeledAccountMap = ['map' => [$caIg->getPlatformId() => $caIg->getId()], 'mapReverse' => [$caIg->getId() => $caIg->getPlatformId()]];
            // $mediaMap is already populated above from DB fetch

            foreach ($dates as $date) {
                // A. IG Account Metrics (Daily)
                // IG Accounts natively return these metrics as daily
                $accountPayload = [
                    'data' => [
                        ['name' => 'reach', 'total_value' => ['value' => rand(100, 500)]],
                        ['name' => 'impressions', 'total_value' => ['value' => rand(150, 600)]],
                        ['name' => 'profile_views', 'total_value' => ['value' => rand(10, 100)]],
                        ['name' => 'website_clicks', 'total_value' => ['value' => rand(0, 5)]],
                        ['name' => 'profile_links_taps', 'total_value' => ['value' => rand(0, 5)]],
                        ['name' => 'follows_and_unfollows', 'total_value' => ['value' => rand(-2, 5)]],
                        ['name' => 'replies', 'total_value' => ['value' => rand(0, 5)]],
                        ['name' => 'accounts_engaged', 'total_value' => ['value' => rand(5, 40)]]
                    ]
                ];
                \Classes\Requests\MetricRequests::processInstagramAccount(
                    ['id' => $page->getId(), 'ig_account' => (string)$caIg->getPlatformId()],
                    $accountPayload,
                    $this->entityManager,
                    $fbParent,
                    $page,
                    \Helpers\Helpers::setLogger('seed.log'),
                    ['map' => [$page->getPlatformId() => $page->getId()]],
                    $date,
                    $date,
                    []
                );

                // B. IG Media Metrics (Lifetime)
                foreach ($mediaEntities as $media) {
                    $mId = (string)$media->getPostId();
                    $currentLifetimeValues[$mId]['reach'] += rand(5, 50);
                    $currentLifetimeValues[$mId]['impressions'] += rand(10, 100);
                    $currentLifetimeValues[$mId]['likes'] += rand(0, 5);
                    $currentLifetimeValues[$mId]['comments'] += rand(0, 2);
                    $currentLifetimeValues[$mId]['saved'] += rand(0, 3);
                    $currentLifetimeValues[$mId]['shares'] += rand(0, 2);
                    $currentLifetimeValues[$mId]['views'] += rand(10, 40);
                    $currentLifetimeValues[$mId]['total_interactions'] = 
                    $currentLifetimeValues[$mId]['likes'] + 
                    $currentLifetimeValues[$mId]['comments'] + 
                    $currentLifetimeValues[$mId]['saved'] + 
                    $currentLifetimeValues[$mId]['shares'];

                    $mediaPayload = [];
                    foreach ($currentLifetimeValues[$mId] as $n => $v) {
                        $mediaPayload[] = ['name' => $n, 'values' => [['value' => $v, 'end_time' => $date . 'T07:00:00+0000']]];
                    }

                    $metrics = \Classes\Conversions\FacebookOrganicMetricConvert::igMediaMetrics(
                        $mediaPayload, $date, $page, $media, $fbParent, $caIg, \Helpers\Helpers::setLogger('seed.log')
                    );

                    foreach ($metrics as $metric) {
                        $metric->post = $media; // Attach actual Doctrine Entity
                        $metric->page = $page;
                        $metric->account = $fbParent;
                        $metric->channeledAccount = $caIg;
                        $metric->platformId = $mId; // Fallback
                        $allIgMediaMetrics->add($metric);
                    }
                }
            } // end dates loop

            $output->writeln("  - Running MetricsProcessor massive batch upsert (transactional) for IG Media...");
            // Execute Batch Processing for IG Media Metrics
            if (!$allIgMediaMetrics->isEmpty()) {
                try {
                    $this->entityManager->getConnection()->beginTransaction();

                    $metricConfigMap = \Classes\MetricsProcessor::processMetricConfigs(
                        metrics: $allIgMediaMetrics,
                        manager: $this->entityManager,
                        pageMap: $pageMap,
                        postMap: $mediaMap,
                        accountMap: $accountMap,
                        channeledAccountMap: $channeledAccountMap,
                    );

                    $metricMap = \Classes\MetricsProcessor::processMetrics(
                        metrics: $allIgMediaMetrics,
                        manager: $this->entityManager,
                        metricConfigMap: $metricConfigMap,
                    );

                    \Classes\MetricsProcessor::processChanneledMetrics(
                        metrics: $allIgMediaMetrics,
                        manager: $this->entityManager,
                        metricMap: $metricMap,
                        logger: \Helpers\Helpers::setLogger('seed.log'),
                    );

                    $this->entityManager->getConnection()->commit();
                } catch (\Exception $e) {
                    if ($this->entityManager->getConnection()->isTransactionActive()) {
                        $this->entityManager->getConnection()->rollBack();
                    }
                    throw $e;
                }
            }
            
            $progress->advance();
        }
        $progress->finish();
        $output->writeln("");
    }

    private function seedDailyMetrics(array $dates, Channel $channel, array $metricDefs, int $gAccId, ?int $caId = null, ?int $gCpId = null, ?int $cpId = null, ?int $postId = null, ?int $pageId = null, ?string $accName = null, ?string $caPId = null, ?string $gCpPId = null, ?string $cpPId = null, ?string $agPId = null, ?string $pageUrl = null, ?string $postPId = null): void
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
                    gCpPId: $gCpPId,
                    cpPId: $cpPId,
                    agPId: $agPId,
                    pageUrl: $pageUrl,
                    postPId: $postPId,
                    setHash: null
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
        $queryId = null, $countryId = null, $deviceId = null, $productId = null, $customerId = null, $orderId = null, $creativeId = null,
        ?string $accName = null, ?string $caPId = null, ?string $gCpPId = null, ?string $cpPId = null, ?string $agPId = null, ?string $adPId = null, ?string $pageUrl = null, ?string $postPId = null,
        ?string $queryPId = null, ?string $countryPId = null, ?string $devicePId = null, ?string $productPId = null, ?string $customerPId = null, ?string $orderPId = null, ?string $creativePId = null,
        ?string $data = null,
        ?string $setHash = null
    ): void {
        $sig = KeyGenerator::generateMetricConfigKey(
            channel: $channel,
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
            'dimension_set_hash' => $setHash ?: ( $setId ? null : \Classes\KeyGenerator::generateDimensionsHash([]) ), // Default unsegmented hash for null ID
            'data' => $data
        ];
        if (count($this->bufferConfigs) >= self::BULK_SIZE) { $this->flushAll(); }
    }

    private function flushAll(): void
    {
        if (empty($this->bufferConfigs)) return;
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
                'updated_at' => $now
            ]; 
        }
        
        $this->bulkInsert('metric_configs', array_values($configsToInsert), ['dimension_set_id']);
        
        $quotedSigs = array_map(fn($c) => $this->conn->quote($c['config_signature']), $this->bufferConfigs);
        $idMap = $this->conn->fetchAllKeyValue("SELECT config_signature, id FROM metric_configs WHERE config_signature IN (" . implode(',', $quotedSigs) . ")");
        
        $metricsToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $cfgId = $idMap[$c['config_signature']] ?? null;
            if (!$cfgId) continue;
            
            $dimHash = $c['dimension_set_hash'] ?? md5((string)$c['dimension_set_id']);
            $mKey = $cfgId . '|' . $dimHash . '|' . $c['metric_date'];
            
            $metricsToInsert[$mKey] = [
                'metric_config_id' => $cfgId, 
                'dimensions_hash' => $dimHash, 
                'value' => $c['value'], 
                'metric_date' => $c['metric_date'],
                'created_at' => $now, 
                'updated_at' => $now
            ];
        }
        $this->bulkInsert('metrics', array_values($metricsToInsert), ['value']);
        
        $quotedConfigIds = array_map('intval', array_values($idMap));
        if (empty($quotedConfigIds)) return;
        
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
            if ($mId) $chanToInsert[] = [
                'platform_id' => $this->generatePlatformId(), 
                'channel' => $c['channel'], 
                'metric_id' => $mId, 
                'dimension_set_id' => $c['dimension_set_id'], 
                'platform_created_at' => $c['metric_date'] . ' 00:00:00',
                'data' => $c['data'],
                'created_at' => $now, 
                'updated_at' => $now
            ];
        }
        $this->bulkInsert('channeled_metrics', $chanToInsert);
        $this->bufferConfigs = [];
    }

    private function bulkInsert(string $table, array $rows, array $updateCols = []): void
    {
        if (empty($rows)) return;
        
        if (empty($updateCols)) {
            $columns = array_keys($rows[0]);
            $vals = [];
            foreach ($rows as $row) { $vals[] = "(" . implode(',', array_map(fn($v) => is_null($v) ? 'NULL' : $this->conn->quote((string)$v), array_values($row))) . ")"; }
            
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
