<?php

namespace Commands;

use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:recalculate-metric-config-signatures',
    description: 'Recalculates config_signature for all MetricConfigs using the current KeyGenerator. Safe to re-run anytime.'
)]
class RecalculateMetricConfigSignaturesCommand extends Command
{
    private EntityManagerInterface $entityManager;

    private const DEFAULT_BATCH_SIZE = 10000;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows per batch', self::DEFAULT_BATCH_SIZE)
            ->addOption('from-id', null, InputOption::VALUE_REQUIRED, 'Start ID (inclusive)')
            ->addOption('to-id', null, InputOption::VALUE_REQUIRED, 'End ID (inclusive)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Update every row, even if signature matches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        $fromId = $input->getOption('from-id') ? (int) $input->getOption('from-id') : null;
        $toId = $input->getOption('to-id') ? (int) $input->getOption('to-id') : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $conn = $this->entityManager->getConnection();

        // Determine total row count
        $countSql = 'SELECT COUNT(*) AS total FROM metric_configs';
        $countParams = [];
        if ($fromId !== null) {
            $countSql .= ' WHERE id >= :fromId';
            $countParams['fromId'] = $fromId;
        }
        if ($fromId !== null && $toId !== null) {
            $countSql .= ' AND id <= :toId';
            $countParams['toId'] = $toId;
        } elseif ($toId !== null) {
            $countSql .= ' WHERE id <= :toId';
            $countParams['toId'] = $toId;
        }

        $total = (int) $conn->fetchOne($countSql, $countParams);

        if ($total === 0) {
            $output->writeln('<info>No metric configs found.</info>');
            return Command::SUCCESS;
        }

        $rangeInfo = '';
        if ($fromId !== null || $toId !== null) {
            $rangeInfo = " (ID range filter applied)";
        }

        $output->writeln(sprintf(
            '<info>Recalculating signatures for %d metric configs%s</info>',
            $total, $rangeInfo
        ));

        if ($dryRun) {
            $output->writeln('<comment>Dry-run mode: no changes will be written.</comment>');
        }
        if ($force) {
            $output->writeln('<comment>Force mode: all rows will be updated regardless of current signature.</comment>');
        }

        $selectSql = $this->buildSelectSql();

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $updatedCount = 0;
        $batchesProcessed = 0;
        $offset = 0;

        while (true) {
            $rows = $conn->fetchAllAssociative(
                $selectSql . ' OFFSET ' . (int) $offset . ' LIMIT ' . (int) $batchSize,
                []
            );

            if (empty($rows)) {
                break;
            }

            $updates = [];
            foreach ($rows as $row) {
                $newSignature = $this->generateSignatureFromRow($row);
                if ($force || $newSignature !== $row['config_signature']) {
                    $updates[] = [
                        'id' => (int) $row['id'],
                        'signature' => $newSignature,
                    ];
                }
            }

            if (!empty($updates) && !$dryRun) {
                $this->batchUpdateSignatures($conn, $updates);
            }

            $updatedCount += count($updates);
            $batchesProcessed++;
            $progressBar->advance(count($rows));

            $offset += count($rows);

            if ($batchesProcessed % 10 === 0) {
                $this->entityManager->clear();
                gc_collect_cycles();
            }
        }

        $progressBar->finish();
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("<info>Dry-run complete. $updatedCount configs would be updated.</info>");
        } else {
            $output->writeln("<info>Done. $updatedCount config signatures updated.</info>");
        }

        return Command::SUCCESS;
    }

    private function buildSelectSql(): string
    {
        return "
            SELECT
                mc.id,
                mc.config_signature,
                mc.channel,
                mc.name,
                mc.period,
                mc.account_id,
                a.name AS account_name,
                mc.channeled_account_id,
                ca.platform_id AS channeled_account_platform_id,
                mc.campaign_id,
                cm.campaign_id AS campaign_campaign_id,
                mc.channeled_campaign_id,
                cc.platform_id AS channeled_campaign_platform_id,
                mc.channeled_ad_group_id,
                cag.platform_id AS channeled_ad_group_platform_id,
                mc.channeled_ad_id,
                cad.platform_id AS channeled_ad_platform_id,
                mc.creative_id,
                cr.creative_id AS creative_creative_id,
                mc.page_id,
                p.url AS page_url,
                mc.query_id,
                q.query AS query_query,
                mc.post_id,
                po.post_id AS post_post_id,
                mc.product_id,
                pr.product_id AS product_product_id,
                mc.customer_id,
                cu.email AS customer_email,
                mc.order_id,
                o.order_id AS order_order_id,
                mc.country_id,
                co.code AS country_code,
                mc.device_id,
                d.type AS device_type,
                mc.dimension_set_id,
                ds.hash AS dimension_set_hash,
                mc.location_id,
                l.platform_id AS location_platform_id,
                mc.state_id,
                s.name AS state_name,
                mc.city_id,
                ci.name AS city_name,
                mc.event_id,
                e.name AS event_name,
                mc.channeled_event_id,
                ce.platform_id AS channeled_event_platform_id
            FROM metric_configs mc
            LEFT JOIN accounts a ON mc.account_id = a.id
            LEFT JOIN channeled_accounts ca ON mc.channeled_account_id = ca.id
            LEFT JOIN campaigns cm ON mc.campaign_id = cm.id
            LEFT JOIN channeled_campaigns cc ON mc.channeled_campaign_id = cc.id
            LEFT JOIN channeled_ad_groups cag ON mc.channeled_ad_group_id = cag.id
            LEFT JOIN channeled_ads cad ON mc.channeled_ad_id = cad.id
            LEFT JOIN creatives cr ON mc.creative_id = cr.id
            LEFT JOIN pages p ON mc.page_id = p.id
            LEFT JOIN queries q ON mc.query_id = q.id
            LEFT JOIN posts po ON mc.post_id = po.id
            LEFT JOIN products pr ON mc.product_id = pr.id
            LEFT JOIN customers cu ON mc.customer_id = cu.id
            LEFT JOIN orders o ON mc.order_id = o.id
            LEFT JOIN countries co ON mc.country_id = co.id
            LEFT JOIN devices d ON mc.device_id = d.id
            LEFT JOIN dimension_sets ds ON mc.dimension_set_id = ds.id
            LEFT JOIN locations l ON mc.location_id = l.id
            LEFT JOIN states s ON mc.state_id = s.id
            LEFT JOIN cities ci ON mc.city_id = ci.id
            LEFT JOIN events e ON mc.event_id = e.id
            LEFT JOIN channeled_events ce ON mc.channeled_event_id = ce.id
            ORDER BY mc.id ASC
        ";
    }

    private function generateSignatureFromRow(array $row): string
    {
        return KeyGenerator::generateMetricConfigKey(
            channel: (int) $row['channel'],
            name: $row['name'],
            period: $row['period'],
            account: $row['account_name'],
            channeledAccount: $row['channeled_account_platform_id'],
            campaign: $row['campaign_campaign_id'],
            channeledCampaign: $row['channeled_campaign_platform_id'],
            channeledAdGroup: $row['channeled_ad_group_platform_id'],
            channeledAd: $row['channeled_ad_platform_id'],
            creative: $row['creative_creative_id'],
            page: $row['page_url'],
            query: $row['query_query'],
            post: $row['post_post_id'],
            product: $row['product_product_id'],
            customer: $row['customer_email'],
            order: $row['order_order_id'],
            country: $row['country_code'],
            device: $row['device_type'],
            dimensionSet: $row['dimension_set_hash'],
            location: $row['location_platform_id'],
            state: $row['state_name'],
            city: $row['city_name'],
            event: $row['event_name'],
            channeledEvent: $row['channeled_event_platform_id']
        );
    }

    private function batchUpdateSignatures(\Doctrine\DBAL\Connection $conn, array $updates): void
    {
        $cases = [];
        $ids = [];
        $params = [];
        $i = 0;
        foreach ($updates as $update) {
            $sigParam = "sig_{$i}";
            $idParam = "id_{$i}";
            $cases[] = "WHEN :{$idParam} THEN :{$sigParam}";
            $params[$sigParam] = $update['signature'];
            $params[$idParam] = $update['id'];
            $ids[] = $update['id'];
            $i++;
        }
        $idPlaceholders = [];
        foreach ($updates as $i => $update) {
            $idPlaceholders[] = ":id_{$i}";
        }
        $conn->executeStatement(
            'UPDATE metric_configs SET config_signature = CASE id ' . implode(' ', $cases)
            . ' END WHERE id IN (' . implode(',', $idPlaceholders) . ')',
            $params
        );
    }
}
