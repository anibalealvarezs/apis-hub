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
        $this->addOption('channels', 'c', InputOption::VALUE_OPTIONAL, 'Channels to seed', 'facebook_marketing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channels = explode(',', $input->getOption('channels'));
        $output->writeln("<info>🚀 Seeding Ultra Realistic Demo Data (Names & PlatformIDs)...</info>");
        ini_set('memory_limit', '4G');
        set_time_limit(0);

        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $tables = ['channeled_metrics', 'metrics', 'metric_configs', 'dimension_set_items', 'dimension_sets', 'dimension_values', 'dimension_keys', 'channeled_ads', 'channeled_ad_groups', 'channeled_campaigns', 'campaigns', 'channeled_accounts', 'accounts', 'pages', 'queries', 'posts', 'countries', 'devices'];
        foreach ($tables as $table) { $this->conn->executeStatement("TRUNCATE TABLE $table"); }

        $this->seedBasicEntities();
        $this->seedDimensionHierarchy($output);

        if (in_array('google_search_console', $channels)) { $this->seedGscData($output); }
        if (in_array('facebook_marketing', $channels)) { $this->seedFacebookMarketingRealistic($output); }
        if (in_array('facebook_organic', $channels)) { $this->seedFacebookOrganicData($output); }

        $this->flushAll();
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $output->writeln("\n<info>✅ Ultra Realistic Seeding Completed!</info>");
        return Command::SUCCESS;
    }

    private function generatePlatformId(): string
    {
        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 5)), 0, 15);
    }

    private function seedBasicEntities(): void
    {
        foreach ([CountryEnum::USA, CountryEnum::ESP, CountryEnum::MEX, CountryEnum::COL] as $c) {
            $country = new Country(); $country->addCode($c)->addName($c->getFullName()); $this->entityManager->persist($country);
        }
        $this->entityManager->flush();
    }

    private function seedDimensionHierarchy(OutputInterface $output): void
    {
        $output->writeln("🛠️ Populating Dimensions...");
        $this->conn->insert('dimension_keys', ['name' => 'age', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $ageK = $this->conn->lastInsertId();
        $this->conn->insert('dimension_keys', ['name' => 'gender', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $genK = $this->conn->lastInsertId();
        $ageValIds = [];
        foreach ($this->ages as $age) {
            $this->conn->insert('dimension_values', ['dimension_key_id' => $ageK, 'value' => $age, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $ageValIds[$age] = $this->conn->lastInsertId();
        }
        $genValIds = [];
        foreach ($this->genders as $gen) {
            $this->conn->insert('dimension_values', ['dimension_key_id' => $genK, 'value' => $gen, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $genValIds[$gen] = $this->conn->lastInsertId();
        }
        foreach ($this->ages as $age) {
            foreach ($this->genders as $gen) {
                $h = md5("age:{$age}|gender:{$gen}");
                $this->conn->insert('dimension_sets', ['hash' => $h, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $setId = $this->conn->lastInsertId();
                $this->dimensionSetCache["$age|$gen"] = $setId;
                $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $ageValIds[$age]]);
                $this->conn->insert('dimension_set_items', ['dimension_set_id' => $setId, 'dimension_value_id' => $genValIds[$gen]]);
            }
        }
    }

    private function seedGscData(OutputInterface $output): void
    {
        $output->writeln("🔍 GSC seeding...");
        $pages = [];
        for ($i = 0; $i < 20; $i++) {
            $page = new Page(); $page->addUrl("https://demo.site/p$i")->addTitle($this->faker->sentence(3))->addHostname("demo.site")->addPlatformId($this->generatePlatformId());
            $this->entityManager->persist($page); $pages[] = $page;
        }
        $this->entityManager->flush();
        foreach ($this->getDates(60) as $date) {
            foreach ($pages as $p) { $this->queueMetric(Channel::google_search_console, 'clicks', $date, rand(10, 100), null, $p->getId()); }
        }
        $this->flushAll();
    }

    private function seedFacebookMarketingRealistic(OutputInterface $output): void
    {
        $output->writeln("📊 FB Marketing (30 Acc, Core Breakdown Hierarchy)...");
        $fbParent = new Account(); $fbParent->addName("Client " . $this->faker->company()); $this->entityManager->persist($fbParent); $this->entityManager->flush();
        $gId = $fbParent->getId();
        $dates = $this->getDates(60); $fbChan = Channel::facebook_marketing;
        $progressBar = new ProgressBar($output, 30); $progressBar->start();
        for ($i = 0; $i < 30; $i++) {
            $ca = new ChanneledAccount(); $ca->addPlatformId($this->generatePlatformId())->addAccount($fbParent)->addType(AccountType::META_AD_ACCOUNT)->addChannel($fbChan->value)->addName($this->faker->company());
            $this->entityManager->persist($ca); $this->entityManager->flush();
            $caId = $ca->getId();

            $numC = rand(3, 5);
            for ($c = 0; $c < $numC; $c++) {
                $cID = $this->generatePlatformId();
                $campG = new Campaign(); $campG->addCampaignId($cID)->addName($this->faker->catchPhrase()); $this->entityManager->persist($campG);
                $cp = new ChanneledCampaign(); $cp->addPlatformId($cID)->addChanneledAccount($ca)->addCampaign($campG)->addChannel($fbChan->value)->addBudget(rand(100, 500));
                $this->entityManager->persist($cp); $this->entityManager->flush();
                $cpId = $cp->getId();

                for ($s = 0; $s < rand(1, 2); $s++) {
                    $agIdPlat = $this->generatePlatformId();
                    $ag = new ChanneledAdGroup(); $ag->addPlatformId($agIdPlat)->addChanneledAccount($ca)->addChannel($fbChan->value)->addName("AdSet: " . $this->faker->words(3, true))->addChanneledCampaign($cp);
                    $this->entityManager->persist($ag); $this->entityManager->flush();
                    $agId = $ag->getId();

                    for ($a = 0; $a < rand(1, 2); $a++) {
                        $adIdPlat = $this->generatePlatformId();
                        $ad = new ChanneledAd(); $ad->addPlatformId($adIdPlat)->addChanneledAccount($ca)->addChannel($fbChan->value)->addName("Ad: " . $this->faker->words(2, true))->addChanneledAdGroup($ag);
                        $this->entityManager->persist($ad); $this->entityManager->flush();
                        $this->seedRealisticAdDaily($dates, $gId, $caId, $campG->getId(), $cpId, $agId, $ad->getId());
                    }
                }
            }
            $progressBar->advance(); $this->entityManager->clear();
            $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['id' => $gId]);
        }
        $progressBar->finish(); $output->writeln("");
    }

    private function seedRealisticAdDaily($dates, $gId, $caId, $gCpId, $cpId, $agId, $adId): void
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
                foreach ($data as $name => $val) { $this->queueMetric($fbChan, $name, $date, $val, $setId, null, $adId, $agId, $cpId, $caId, $gId, $gCpId); }
            }
        }
    }

    private function seedFacebookOrganicData(OutputInterface $output): void
    {
        $output->writeln("📊 FB Organic seeding...");
        $fbParent = $this->entityManager->getRepository(Account::class)->findOneBy(['name' => "Client Demo"]);
        foreach ($this->getDates(60) as $date) {
            $this->queueMetric(Channel::facebook_organic, 'page_reach', $date, rand(100, 1000), null, null, null, null, null, null, $fbParent?->getId());
        }
    }

    private function getDates($days): array
    {
        $dates = []; $d = new DateTime("-$days days");
        for ($i = 0; $i < $days; $i++) { $dates[] = $d->format('Y-m-d'); $d->modify('+1 day'); }
        return $dates;
    }

    private function queueMetric(Channel $channel, $name, $date, $value, $setId = null, $pageId = null, $adId = null, $agId = null, $cpId = null, $caId = null, $gAccId = null, $gCpId = null): void
    {
        $sig = md5($channel->value.'|'.$name.'|'.$date.'|'.$pageId.'|'.$adId.'|'.$agId.'|'.$cpId.'|'.$caId.'|'.$gAccId.'|'.$gCpId);
        $this->bufferConfigs[] = ['channel' => $channel->value, 'name' => $name, 'period' => 'daily', 'metric_date' => $date, 'page_id' => $pageId, 'channeled_ad_id' => $adId, 'channeled_ad_group_id' => $agId, 'channeled_campaign_id' => $cpId, 'campaign_id' => $gCpId, 'channeled_account_id' => $caId, 'account_id' => $gAccId, 'config_signature' => $sig, 'value' => (float)$value, 'dimension_set_id' => $setId];
        if (count($this->bufferConfigs) >= self::BULK_SIZE) { $this->flushAll(); }
    }

    private function flushAll(): void
    {
        if (empty($this->bufferConfigs)) return;
        $now = date('Y-m-d H:i:s');
        $configsToInsert = array_map(function($row) use ($now) { return ['channel' => $row['channel'], 'name' => $row['name'], 'period' => 'daily', 'metric_date' => $row['metric_date'], 'page_id' => $row['page_id'], 'channeled_ad_id' => $row['channeled_ad_id'], 'channeled_ad_group_id' => $row['channeled_ad_group_id'], 'channeled_campaign_id' => $row['channeled_campaign_id'], 'campaign_id' => $row['campaign_id'], 'channeled_account_id' => $row['channeled_account_id'], 'account_id' => $row['account_id'], 'config_signature' => $row['config_signature'], 'created_at' => $now, 'updated_at' => $now]; }, $this->bufferConfigs);
        $this->bulkInsert('metric_configs', $configsToInsert);
        $idMap = $this->conn->fetchAllKeyValue("SELECT config_signature, id FROM metric_configs WHERE config_signature IN (" . implode(',', array_map(fn($c) => $this->conn->quote($c['config_signature']), $this->bufferConfigs)) . ")");
        $metricsToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $cfgId = $idMap[$c['config_signature']] ?? null;
            if ($cfgId) $metricsToInsert[] = ['metric_config_id' => $cfgId, 'dimensions_hash' => md5((string)$c['dimension_set_id']), 'value' => $c['value'], 'created_at' => $now, 'updated_at' => $now];
        }
        $this->bulkInsert('metrics', $metricsToInsert);
        $mMap = $this->conn->fetchAllKeyValue("SELECT metric_config_id, id FROM metrics WHERE metric_config_id IN (" . implode(',', array_values($idMap)) . ")");
        $chanToInsert = [];
        foreach ($this->bufferConfigs as $c) {
            $mId = $mMap[$idMap[$c['config_signature']] ?? 0] ?? null;
            if ($mId) $chanToInsert[] = ['platform_id' => $this->generatePlatformId(), 'channel' => $c['channel'], 'metric_id' => $mId, 'dimension_set_id' => $c['dimension_set_id'], 'platform_created_at' => $c['metric_date'], 'created_at' => $now, 'updated_at' => $now];
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
        $this->conn->executeStatement("INSERT IGNORE INTO $table (" . implode(',', $columns) . ") VALUES " . implode(',', $vals));
    }
}
