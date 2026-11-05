<?php

declare(strict_types=1);

namespace Services\Catalog;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Channel;
use Enums\BillingEvent;
use Enums\CampaignBuyingType;
use Enums\CampaignObjective;
use Enums\CampaignStatus;
use Enums\OptimizationGoal;
use InvalidArgumentException;
use Throwable;

final class CatalogReadService
{
    private const array ADS_CHANNELS = [
        'facebook_marketing' => 'Meta Ads',
        'google_ads'         => 'Google Ads',
    ];

    private const array SUPPORTED_CHANNELS = [
        'facebook_marketing'    => 'Meta Ads',
        'google_ads'            => 'Google Ads',
        'facebook_organic'      => 'Meta Organic',
        'google_search_console' => 'Google Search Console',
        'google_analytics'      => 'Google Analytics',
        'mailchimp'             => 'Mailchimp',
        'facebook_leads'        => 'Meta Leads',
    ];

    private const array METRIC_NAME_MAP = [
        'spend'               => ['spend', 'spend_daily', 'cost', 'cost_daily'],
        'impressions'         => ['impressions', 'impressions_daily', 'page_impressions', 'post_impressions'],
        'clicks'              => ['clicks', 'clicks_daily', 'link_clicks', 'link_clicks_daily', 'post_clicks'],
        'reach'               => ['reach', 'reach_daily', 'page_impressions_unique', 'post_impressions_unique'],
        'conversions'         => ['conversions', 'conversions_daily', 'results', 'results_daily', 'actions', 'actions_daily', 'keyevents', 'key_events'],
        'purchases'           => ['purchases', 'purchases_daily'],
        'purchase_value'      => ['purchase_value', 'purchase_value_daily', 'conversion_value', 'conversion_value_daily', 'totalrevenue', 'total_revenue'],
        'leads'               => ['leads', 'leads_daily'],
        'roas_purchase_ratio' => ['purchase_roas', 'purchase_roas_daily'],
        'views'               => ['views', 'page_views', 'pageviews', 'screenpageviews', 'screen_page_views', 'video_views'],
        'sessions'            => ['sessions', 'engagedsessions', 'engaged_sessions'],
        'total_users'         => ['totalusers', 'total_users', 'activeusers', 'active_users', 'users'],
        'event_count'         => ['eventcount', 'event_count', 'events'],
        'likes'               => ['likes', 'reactions', 'post_reactions_like_total'],
        'comments'            => ['comments', 'post_comments'],
        'shares'              => ['shares', 'post_shares'],
        'saves'               => ['saves', 'saved', 'post_saves'],
        'total_interactions'  => ['total_interactions', 'engagement', 'engaged_users', 'post_engaged_users'],
        'sends'               => ['sends', 'emails_sent', 'sent'],
        'opens'               => ['opens', 'unique_opens', 'opens_total'],
        'bounces'             => ['bounces', 'hard_bounces', 'soft_bounces'],
        'unsubscribes'        => ['unsubscribes', 'unsubscribed'],
        'position_raw'        => ['position', 'average_position'],
        'open_rate_raw'       => ['open_rate'],
        'click_rate_raw'      => ['click_rate'],
    ];

    private Connection $connection;

    /** @var array<string, int> */
    private array $channelNameToIdCache = [];

    /** @var array<int, string> */
    private array $channelIdToNameCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?Connection $connection = null,
    ) {
        $this->connection = $connection ?? $this->em->getConnection();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function capabilities(array $payload = []): array
    {
        $statuses = array_map(static fn (CampaignStatus $s) => $s->value, CampaignStatus::cases());
        $objectives = array_map(static fn (CampaignObjective $o) => $o->value, CampaignObjective::cases());
        $buyingTypes = array_map(static fn (CampaignBuyingType $b) => $b->value, CampaignBuyingType::cases());
        $optimizationGoals = array_map(static fn (OptimizationGoal $g) => $g->value, OptimizationGoal::cases());
        $billingEvents = array_map(static fn (BillingEvent $b) => $b->value, BillingEvent::cases());

        $adsMetrics = [
            'spend',
            'impressions',
            'clicks',
            'reach',
            'conversions',
            'purchases',
            'purchase_value',
            'leads',
            'ctr',
            'cpc',
            'cpm',
            'cost_per_conversion',
            'roas_purchase',
        ];

        $accountChannels = [
            'facebook_marketing',
            'google_ads',
            'facebook_organic',
            'google_search_console',
            'google_analytics',
            'mailchimp',
        ];
        $campaignChannels = [
            'facebook_marketing',
            'google_ads',
            'mailchimp',
        ];
        $emailMetrics = [
            'sends',
            'opens',
            'clicks',
            'open_rate',
            'click_rate',
            'bounces',
            'unsubscribes',
        ];

        $entities = [
            'accounts' => [
                'entity_type'       => 'accounts',
                'label'             => 'Accounts',
                'family'            => 'ads_hierarchy',
                'phase'             => 1,
                'supported'         => true,
                'channels'          => $accountChannels,
                'filters'           => [
                    'channel' => $accountChannels,
                    'status'  => ['ENABLED', 'DISABLED'],
                    'type'    => ['ADVERTISING', 'ADS', 'META_ADS', 'GOOGLE_ADS', 'PAGE', 'INSTAGRAM', 'SITE', 'PROPERTY', 'AUDIENCE', 'LIST'],
                ],
                'sortable'          => ['name', 'platform_id', 'channel', 'updated_at', 'platform_created_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => array_values(array_unique(array_merge($adsMetrics, ['views', 'sessions', 'total_users', 'event_count', 'position'], $emailMetrics))),
                'activity_coverage' => 'milestones_only',
            ],
            'campaigns' => [
                'entity_type'       => 'campaigns',
                'label'             => 'Campaigns',
                'family'            => 'ads_hierarchy',
                'phase'             => 1,
                'supported'         => true,
                'channels'          => $campaignChannels,
                'filters'           => [
                    'channel'          => $campaignChannels,
                    'status'           => array_values(array_unique(array_merge($statuses, ['SENT', 'SENDING', 'SAVE', 'SCHEDULE', 'PAUSED']))),
                    'objective'        => $objectives,
                    'buying_type'      => $buyingTypes,
                    'parent_reference' => ['accounts'],
                ],
                'sortable'          => ['name', 'status', 'budget', 'objective', 'buying_type', 'start_date', 'end_date', 'updated_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => array_values(array_unique(array_merge($adsMetrics, $emailMetrics))),
                'activity_coverage' => 'milestones_and_events',
            ],
            'ad-sets' => [
                'entity_type'       => 'ad-sets',
                'label'             => 'Ad Sets',
                'family'            => 'ads_hierarchy',
                'phase'             => 1,
                'supported'         => true,
                'channels'          => ['facebook_marketing'],
                'filters'           => [
                    'channel'           => ['facebook_marketing'],
                    'status'            => $statuses,
                    'optimization_goal' => $optimizationGoals,
                    'billing_event'     => $billingEvents,
                    'parent_reference'  => ['accounts', 'campaigns'],
                ],
                'sortable'          => ['name', 'status', 'optimization_goal', 'billing_event', 'start_date', 'end_date', 'updated_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => $adsMetrics,
                'activity_coverage' => 'milestones_only',
            ],
            'ad-groups' => [
                'entity_type'       => 'ad-groups',
                'label'             => 'Ad Groups',
                'family'            => 'ads_hierarchy',
                'phase'             => 1,
                'supported'         => true,
                'channels'          => ['google_ads'],
                'filters'           => [
                    'channel'           => ['google_ads'],
                    'status'            => $statuses,
                    'optimization_goal' => $optimizationGoals,
                    'billing_event'     => $billingEvents,
                    'parent_reference'  => ['accounts', 'campaigns'],
                ],
                'sortable'          => ['name', 'status', 'optimization_goal', 'billing_event', 'start_date', 'end_date', 'updated_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => $adsMetrics,
                'activity_coverage' => 'milestones_only',
            ],
            'ads' => [
                'entity_type'       => 'ads',
                'label'             => 'Ads',
                'family'            => 'ads_hierarchy',
                'phase'             => 1,
                'supported'         => true,
                'channels'          => array_keys(self::ADS_CHANNELS),
                'filters'           => [
                    'channel'          => array_keys(self::ADS_CHANNELS),
                    'status'           => $statuses,
                    'parent_reference' => ['accounts', 'campaigns', 'ad-sets', 'ad-groups'],
                ],
                'sortable'          => ['name', 'status', 'platform_id', 'updated_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => $adsMetrics,
                'activity_coverage' => 'milestones_only',
            ],
            'pages' => [
                'entity_type'       => 'pages',
                'label'             => 'Pages',
                'family'            => 'content_search_analytics',
                'phase'             => 2,
                'supported'         => true,
                'channels'          => ['facebook_organic', 'google_search_console', 'google_analytics'],
                'filters'           => [
                    'channel'          => ['facebook_organic', 'google_search_console', 'google_analytics'],
                    'parent_reference' => ['accounts'],
                ],
                'sortable'          => ['title', 'url', 'hostname', 'updated_at'],
                'default_sort'      => ['field' => 'title', 'dir' => 'ASC'],
                'metrics'           => ['impressions', 'clicks', 'ctr', 'position', 'views', 'sessions', 'reach', 'total_interactions'],
                'activity_coverage' => 'milestones_only',
            ],
            'posts' => [
                'entity_type'       => 'posts',
                'label'             => 'Posts',
                'family'            => 'content_search_analytics',
                'phase'             => 2,
                'supported'         => true,
                'channels'          => ['facebook_organic'],
                'filters'           => [
                    'channel'          => ['facebook_organic'],
                    'parent_reference' => ['accounts', 'pages'],
                ],
                'sortable'          => ['platform_created_at', 'updated_at', 'post_id'],
                'default_sort'      => ['field' => 'platform_created_at', 'dir' => 'DESC'],
                'metrics'           => ['impressions', 'reach', 'likes', 'comments', 'shares', 'saves', 'total_interactions', 'views', 'clicks'],
                'activity_coverage' => 'milestones_only',
            ],
            'queries' => [
                'entity_type'       => 'queries',
                'label'             => 'Queries',
                'family'            => 'content_search_analytics',
                'phase'             => 2,
                'supported'         => true,
                'channels'          => ['google_search_console', 'google_ads'],
                'filters'           => [
                    'channel'          => ['google_search_console', 'google_ads'],
                    'search_type'      => ['web', 'image', 'video', 'news', 'discover', 'googleNews'],
                    'parent_reference' => ['accounts', 'pages'],
                ],
                'sortable'          => ['query', 'updated_at'],
                'default_sort'      => ['field' => 'query', 'dir' => 'ASC'],
                'metrics'           => ['impressions', 'clicks', 'ctr', 'position'],
                'activity_coverage' => 'daily_observations',
            ],
            'events' => [
                'entity_type'       => 'events',
                'label'             => 'Events',
                'family'            => 'crm_email_and_analytics',
                'phase'             => 2,
                'supported'         => true,
                'channels'          => ['google_analytics', 'mailchimp'],
                'filters'           => [
                    'channel'          => ['google_analytics', 'mailchimp'],
                    'parent_reference' => ['accounts'],
                ],
                'sortable'          => ['name', 'updated_at'],
                'default_sort'      => ['field' => 'name', 'dir' => 'ASC'],
                'metrics'           => ['event_count', 'total_users', 'conversions'],
                'activity_coverage' => 'event_timeline',
            ],
            'customers' => [
                'entity_type'        => 'customers',
                'label'              => 'Customers',
                'family'             => 'crm_email',
                'phase'              => 2,
                'supported'          => true,
                'requires_pii_guard' => true,
                'channels'           => ['mailchimp', 'facebook_leads'],
                'filters'            => [
                    'channel' => ['mailchimp', 'facebook_leads'],
                ],
                'sortable'           => ['email', 'updated_at'],
                'default_sort'       => ['field' => 'updated_at', 'dir' => 'DESC'],
                'metrics'            => ['sends', 'opens', 'clicks', 'leads'],
                'activity_coverage'  => 'event_timeline',
            ],
            'orders' => [
                'entity_type'       => 'orders',
                'label'             => 'Orders',
                'family'            => 'commerce',
                'phase'             => 4,
                'supported'         => false,
                'channels'          => ['shopify', 'bigcommerce', 'amazon', 'netsuite'],
                'filters'           => [],
                'sortable'          => ['platform_created_at', 'total_price', 'updated_at'],
                'default_sort'      => ['field' => 'platform_created_at', 'dir' => 'DESC'],
                'metrics'           => ['orders', 'total_revenue'],
                'activity_coverage' => 'lifecycle_timestamps',
            ],
            'products' => [
                'entity_type'       => 'products',
                'label'             => 'Products',
                'family'            => 'commerce',
                'phase'             => 4,
                'supported'         => false,
                'channels'          => ['shopify', 'bigcommerce', 'amazon', 'netsuite'],
                'filters'           => [],
                'sortable'          => ['title', 'vendor', 'updated_at'],
                'default_sort'      => ['field' => 'title', 'dir' => 'ASC'],
                'metrics'           => ['orders', 'total_revenue'],
                'activity_coverage' => 'milestones_only',
            ],
        ];

        return [
            'contract_version'     => 1,
            'supported_operations' => ['capabilities', 'list', 'profile', 'activity'],
            'channels'             => self::SUPPORTED_CHANNELS,
            'entities'             => $entities,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function list(string $entityType, array $payload): array
    {
        $entityType = strtolower(trim($entityType));
        $this->assertSupportedEntityType($entityType);

        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
        $page = max(1, (int)($pagination['page'] ?? $payload['page'] ?? 1));
        $perPage = max(1, min(100, (int)($pagination['per_page'] ?? $payload['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $sortConfig = is_array($payload['sort'] ?? null) ? $payload['sort'] : [];
        $requestedSort = (string)($sortConfig['field'] ?? $payload['sort_field'] ?? 'name');
        $requestedDir = strtoupper((string)($sortConfig['dir'] ?? $payload['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $channelFilter = isset($filters['channel']) && is_string($filters['channel']) && trim($filters['channel']) !== ''
            ? strtolower(trim($filters['channel']))
            : null;

        // Implicit channel narrowing for channel-specific entity types
        if ($entityType === 'ad-sets') {
            if ($channelFilter !== null && $channelFilter !== 'facebook_marketing') {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, []);
            }
            $channelFilter = 'facebook_marketing';
        } elseif ($entityType === 'ad-groups') {
            if ($channelFilter !== null && $channelFilter !== 'google_ads') {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, []);
            }
            $channelFilter = 'google_ads';
        } elseif ($entityType === 'posts') {
            if ($channelFilter !== null && $channelFilter !== 'facebook_organic') {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, []);
            }
            $channelFilter = 'facebook_organic';
        }

        $authorizedScope = is_array($payload['authorized_scope'] ?? null) ? $payload['authorized_scope'] : [];

        // Customers require explicit PII guard
        if ($entityType === 'customers') {
            if (empty($authorizedScope['can_view_pii'])) {
                $empty = $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, []);
                $empty['meta']['pii_restricted'] = true;

                return $empty;
            }
            $permittedAccountsByChannel = $this->resolveAuthorizedAccounts($authorizedScope, $channelFilter);
            $search = isset($payload['search']) && is_string($payload['search']) ? trim($payload['search']) : '';

            return $this->listCustomers($authorizedScope, $permittedAccountsByChannel, $channelFilter, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset);
        }

        $permittedAccountsByChannel = $this->resolveAuthorizedAccounts($authorizedScope, $channelFilter);
        $permittedAccountIds = $this->flattenPermittedAccountIds($permittedAccountsByChannel);

        if ($permittedAccountIds === []) {
            return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
        }

        $search = isset($payload['search']) && is_string($payload['search']) ? trim($payload['search']) : '';

        return match ($entityType) {
            'accounts'  => $this->listAccounts($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'campaigns' => $this->listCampaigns($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'ad-sets'   => $this->listAdGroups('ad-sets', 'facebook_marketing', $permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'ad-groups' => $this->listAdGroups('ad-groups', 'google_ads', $permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'ads'       => $this->listAds($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'pages'     => $this->listPages($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'posts'     => $this->listPosts($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'queries'   => $this->listQueries($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            'events'    => $this->listEvents($permittedAccountIds, $permittedAccountsByChannel, $filters, $search, $requestedSort, $requestedDir, $page, $perPage, $offset),
            default     => throw new InvalidArgumentException("Unsupported catalog entity type '{$entityType}'."),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function profile(string $entityType, string $reference, array $payload): ?array
    {
        $entityType = strtolower(trim($entityType));
        $this->assertSupportedEntityType($entityType);

        $ref = CatalogEntityReference::tryParse($reference);
        if ($ref === null || $ref->getEntityType() !== $entityType) {
            return null;
        }

        $channeledOnlyTypes = ['accounts', 'campaigns', 'ad-sets', 'ad-groups', 'ads', 'posts'];
        if (in_array($entityType, $channeledOnlyTypes, true) && $ref->getLayer() !== 'channeled') {
            return null;
        }

        if ($entityType === 'ad-sets' && $ref->getChannel() !== 'facebook_marketing') {
            return null;
        }
        if ($entityType === 'ad-groups' && $ref->getChannel() !== 'google_ads') {
            return null;
        }
        if ($entityType === 'posts' && $ref->getChannel() !== 'facebook_organic') {
            return null;
        }
        if (!isset(self::SUPPORTED_CHANNELS[$ref->getChannel()])) {
            return null;
        }

        $authorizedScope = is_array($payload['authorized_scope'] ?? null) ? $payload['authorized_scope'] : [];

        if ($entityType === 'customers') {
            if (empty($authorizedScope['can_view_pii'])) {
                return null;
            }
            $permittedAccountsByChannel = $this->resolveAuthorizedAccounts($authorizedScope, null);
            $dateRange = $this->resolveDateRange(is_array($payload['date_range'] ?? null) ? $payload['date_range'] : []);

            return $this->profileCustomer($ref, $authorizedScope, $permittedAccountsByChannel, $dateRange);
        }

        $permittedAccountsByChannel = $this->resolveAuthorizedAccounts($authorizedScope, null);
        $permittedAccountIds = $this->flattenPermittedAccountIds($permittedAccountsByChannel);

        if ($permittedAccountIds === []) {
            return null;
        }

        $dateRange = $this->resolveDateRange(is_array($payload['date_range'] ?? null) ? $payload['date_range'] : []);
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

        return match ($entityType) {
            'accounts'  => $this->profileAccount($ref, $permittedAccountIds, $dateRange),
            'campaigns' => $this->profileCampaign($ref, $permittedAccountIds, $dateRange),
            'ad-sets',
            'ad-groups' => $this->profileAdGroup($ref, $permittedAccountIds, $dateRange),
            'ads'       => $this->profileAd($ref, $permittedAccountIds, $dateRange),
            'pages'     => $this->profilePage($ref, $permittedAccountIds, $permittedAccountsByChannel, $dateRange),
            'posts'     => $this->profilePost($ref, $permittedAccountIds, $permittedAccountsByChannel, $dateRange),
            'queries'   => $this->profileQuery($ref, $permittedAccountIds, $permittedAccountsByChannel, $dateRange, $filters),
            'events'    => $this->profileEvent($ref, $permittedAccountIds, $permittedAccountsByChannel, $dateRange),
            default     => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function activity(string $entityType, string $reference, array $payload): ?array
    {
        $profile = $this->profile($entityType, $reference, $payload);
        if ($profile === null) {
            return null;
        }

        $record = $profile['record'];
        $ref = CatalogEntityReference::parse($record['reference']);

        $milestones = [];

        if (!empty($record['provenance']['platform_created_at'])) {
            $milestones[] = [
                'id'           => 'platform_created:' . $ref->getId(),
                'kind'         => 'created',
                'label'        => 'Created on ' . ($record['channel_label'] ?? $record['channel']),
                'description'  => sprintf('%s (%s) was created in the source platform.', $record['name'], $record['platform_id']),
                'occurred_at'  => $this->normalizeIsoTimestamp((string)$record['provenance']['platform_created_at']),
                'quality'      => 'source_timestamp',
                'quality_label'=> 'Source timestamp',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'platform_id' => $record['platform_id'],
                    'status'      => $record['status'],
                ],
            ];
        }

        $startDate = $record['attributes']['start_date'] ?? null;
        if (is_string($startDate) && trim($startDate) !== '') {
            $milestones[] = [
                'id'           => 'scheduled_start:' . $ref->getId(),
                'kind'         => 'scheduled_start',
                'label'        => 'Scheduled start date',
                'description'  => sprintf('Delivery window start date configured for %s.', $record['name']),
                'occurred_at'  => $this->normalizeIsoTimestamp($startDate),
                'quality'      => 'scheduled_window',
                'quality_label'=> 'Scheduled window',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'start_date' => $startDate,
                ],
            ];
        }

        $endDate = $record['attributes']['end_date'] ?? null;
        if (is_string($endDate) && trim($endDate) !== '') {
            $milestones[] = [
                'id'           => 'scheduled_end:' . $ref->getId(),
                'kind'         => 'scheduled_end',
                'label'        => 'Scheduled end date',
                'description'  => sprintf('Delivery window end date configured for %s.', $record['name']),
                'occurred_at'  => $this->normalizeIsoTimestamp($endDate),
                'quality'      => 'scheduled_window',
                'quality_label'=> 'Scheduled window',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'end_date' => $endDate,
                ],
            ];
        }

        $sendTime = $record['attributes']['send_time'] ?? null;
        if (is_string($sendTime) && trim($sendTime) !== '') {
            $milestones[] = [
                'id'           => 'campaign_sent:' . $ref->getId(),
                'kind'         => 'sent',
                'label'        => 'Campaign dispatched',
                'description'  => sprintf('Email campaign %s was sent on %s.', $record['name'], $record['channel_label'] ?? $record['channel']),
                'occurred_at'  => $this->normalizeIsoTimestamp($sendTime),
                'quality'      => 'source_timestamp',
                'quality_label'=> 'Source timestamp',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'send_time' => $sendTime,
                ],
            ];
        }

        $publishedAt = $record['attributes']['published_at'] ?? null;
        if (is_string($publishedAt) && trim($publishedAt) !== '') {
            $milestones[] = [
                'id'           => 'post_published:' . $ref->getId(),
                'kind'         => 'published',
                'label'        => 'Post published',
                'description'  => sprintf('Content published on %s.', $record['channel_label'] ?? $record['channel']),
                'occurred_at'  => $this->normalizeIsoTimestamp($publishedAt),
                'quality'      => 'source_timestamp',
                'quality_label'=> 'Source timestamp',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'published_at' => $publishedAt,
                    'media_type'   => $record['attributes']['media_type'] ?? null,
                ],
            ];
        }

        $authorizedScope = is_array($payload['authorized_scope'] ?? null) ? $payload['authorized_scope'] : [];
        $canViewPii = !empty($authorizedScope['can_view_pii']);
        $permittedAccountsByChannel = $this->resolveAuthorizedAccounts($authorizedScope, null);
        $permittedAccountIds = $this->flattenPermittedAccountIds($permittedAccountsByChannel);

        $allTimeBounds = $this->fetchEntityMetricBounds(
            $entityType,
            $ref->getId(),
            (int)($record['owning_account_id'] ?? $ref->getId()),
            $this->resolveChannelId($ref->getChannel()),
            $permittedAccountIds
        );

        if ($allTimeBounds['first_date'] !== null) {
            $milestones[] = [
                'id'           => 'first_metric:' . $ref->getId(),
                'kind'         => 'first_metric_observed',
                'label'        => 'First delivery metric observed',
                'description'  => sprintf('Earliest daily performance metric recorded in APIs Hub (%s).', $allTimeBounds['first_date']),
                'occurred_at'  => $allTimeBounds['first_date'] . 'T00:00:00Z',
                'quality'      => 'reporting_day',
                'quality_label'=> 'Reporting day',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'metric_date'   => $allTimeBounds['first_date'],
                    'observed_days' => $allTimeBounds['observed_days'],
                ],
            ];
        }

        if ($allTimeBounds['last_date'] !== null && $allTimeBounds['last_date'] !== $allTimeBounds['first_date']) {
            $milestones[] = [
                'id'           => 'latest_metric:' . $ref->getId(),
                'kind'         => 'latest_metric_observed',
                'label'        => 'Latest delivery metric observed',
                'description'  => sprintf('Most recent daily performance metric recorded in APIs Hub (%s across %d active days).', $allTimeBounds['last_date'], $allTimeBounds['observed_days']),
                'occurred_at'  => $allTimeBounds['last_date'] . 'T23:59:59Z',
                'quality'      => 'reporting_day',
                'quality_label'=> 'Reporting day',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'metric_date'   => $allTimeBounds['last_date'],
                    'observed_days' => $allTimeBounds['observed_days'],
                ],
            ];
        }

        if ($entityType === 'campaigns' && $ref->getChannel() === 'mailchimp') {
            $milestones = array_merge(
                $milestones,
                $this->fetchMailchimpCampaignEventMilestones(
                    $ref->getId(),
                    (string)$record['platform_id'],
                    (int)($record['owning_account_id'] ?? 0),
                    $canViewPii
                )
            );
        } elseif ($entityType === 'events') {
            $milestones = array_merge(
                $milestones,
                $this->fetchEventOccurrenceMilestones(
                    $ref->getId(),
                    (string)$record['name'],
                    $permittedAccountIds,
                    $canViewPii
                )
            );
        } elseif ($entityType === 'customers') {
            $milestones = array_merge(
                $milestones,
                $this->fetchCustomerActivityMilestones(
                    $ref->getId(),
                    $authorizedScope
                )
            );
        }

        if (!empty($record['provenance']['created_at'])) {
            $milestones[] = [
                'id'           => 'first_synced:' . $ref->getId(),
                'kind'         => 'first_synced',
                'label'        => 'First ingested by APIs Hub',
                'description'  => 'Entity record was first persisted in the tenant database.',
                'occurred_at'  => $this->normalizeIsoTimestamp((string)$record['provenance']['created_at']),
                'quality'      => 'sync_observed_at',
                'quality_label'=> 'Sync observation',
                'channel'      => $record['channel'],
                'metadata'     => [],
            ];
        }

        if (!empty($record['provenance']['updated_at'])) {
            $milestones[] = [
                'id'           => 'last_synced:' . $ref->getId(),
                'kind'         => 'synced',
                'label'        => 'Latest sync snapshot verified',
                'description'  => sprintf('Current status observed as %s during latest sync.', $record['status'] ?? 'UNKNOWN'),
                'occurred_at'  => $this->normalizeIsoTimestamp((string)$record['provenance']['updated_at']),
                'quality'      => 'sync_observed_at',
                'quality_label'=> 'Sync observation',
                'channel'      => $record['channel'],
                'metadata'     => [
                    'status' => $record['status'],
                ],
            ];
        }

        // Apply filters
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $kindFilter = isset($filters['kind']) && is_string($filters['kind']) ? trim($filters['kind']) : '';
        $filterStart = isset($filters['start_date']) && is_string($filters['start_date']) ? trim($filters['start_date']) : '';
        $filterEnd = isset($filters['end_date']) && is_string($filters['end_date']) ? trim($filters['end_date']) : '';

        $filtered = array_values(array_filter($milestones, static function (array $item) use ($kindFilter, $filterStart, $filterEnd): bool {
            if ($kindFilter !== '' && $item['kind'] !== $kindFilter) {
                return false;
            }
            $datePart = substr((string)$item['occurred_at'], 0, 10);
            if ($filterStart !== '' && $datePart < $filterStart) {
                return false;
            }
            if ($filterEnd !== '' && $datePart > $filterEnd) {
                return false;
            }

            return true;
        }));

        $sortMode = strtolower((string)($payload['sort'] ?? 'newest')) === 'oldest' ? 'oldest' : 'newest';
        usort($filtered, static function (array $a, array $b) use ($sortMode): int {
            $cmp = strcmp((string)$a['occurred_at'], (string)$b['occurred_at']);
            if ($cmp === 0) {
                $cmp = strcmp((string)$a['id'], (string)$b['id']);
            }

            return $sortMode === 'oldest' ? $cmp : -$cmp;
        });

        $total = count($filtered);
        $limit = max(1, min(100, (int)($payload['limit'] ?? 20)));
        $cursor = isset($payload['cursor']) && is_string($payload['cursor']) && trim($payload['cursor']) !== ''
            ? trim($payload['cursor'])
            : null;

        $startIndex = 0;
        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if (is_string($decoded) && str_contains($decoded, '|')) {
                [, $cursorId] = explode('|', $decoded, 2);
                foreach ($filtered as $idx => $item) {
                    if ($item['id'] === $cursorId) {
                        $startIndex = $idx + 1;
                        break;
                    }
                }
            }
        }

        $slice = array_slice($filtered, $startIndex, $limit);
        $nextCursor = null;
        if ($startIndex + $limit < $total && $slice !== []) {
            $lastItem = $slice[count($slice) - 1];
            $nextCursor = base64_encode($lastItem['occurred_at'] . '|' . $lastItem['id']);
        }

        $coverageLevel = 'milestones_only';
        $coverageNotice = 'Source platforms provide lifecycle milestones, scheduled windows, and sync observations rather than a full audit log of historical field edits.';
        if (($entityType === 'campaigns' && $ref->getChannel() === 'mailchimp') || in_array($entityType, ['events', 'customers'], true)) {
            $coverageLevel = 'event_timeline';
            $coverageNotice = 'Includes recorded source occurrences alongside lifecycle milestones and sync observations.';
        } elseif ($entityType === 'queries') {
            $coverageLevel = 'daily_observations';
            $coverageNotice = 'Search queries record daily impression and ranking bounds across observed reporting dates.';
        }

        $availableKinds = array_values(array_unique(array_merge(
            [
                'created',
                'scheduled_start',
                'scheduled_end',
                'first_metric_observed',
                'latest_metric_observed',
                'first_synced',
                'synced',
            ],
            array_map(static fn (array $m): string => (string)$m['kind'], $milestones)
        )));

        return [
            'data'     => $slice,
            'coverage' => [
                'level'                   => $coverageLevel,
                'complete_change_history' => false,
                'notice'                  => $coverageNotice,
            ],
            'meta'     => [
                'total'           => $total,
                'limit'           => $limit,
                'sort'            => $sortMode,
                'next_cursor'     => $nextCursor,
                'available_kinds' => $availableKinds,
            ],
        ];
    }

    /**
     * Resolves authorized `channeled_accounts` rows from the Facade `authorized_scope` envelope.
     *
     * @param array<string, mixed> $authorizedScope
     * @return array<string, array<int, array<string, mixed>>> Map of channel => list of account rows
     */
    public function resolveAuthorizedAccounts(array $authorizedScope, ?string $channelFilter = null): array
    {
        $channelsScope = $authorizedScope['channels'] ?? null;
        if (!is_array($channelsScope) || $channelsScope === []) {
            return [];
        }

        $resolved = [];

        foreach ($channelsScope as $channelSlug => $scopeEntry) {
            $channelName = strtolower(trim((string)$channelSlug));
            if (!isset(self::SUPPORTED_CHANNELS[$channelName])) {
                continue;
            }
            if ($channelFilter !== null && $channelFilter !== $channelName) {
                continue;
            }
            if (!is_array($scopeEntry)) {
                continue;
            }

            $rawAccountIds = is_array($scopeEntry['account_ids'] ?? null) ? $scopeEntry['account_ids'] : [];
            $accountIds = array_values(array_unique(array_filter(
                array_map(static fn ($id): int => is_numeric($id) ? (int)$id : 0, $rawAccountIds),
                static fn (int $id): bool => $id > 0
            )));

            $rawPlatformIds = is_array($scopeEntry['platform_ids'] ?? null) ? $scopeEntry['platform_ids'] : [];
            $platformVariants = $this->expandPlatformIdVariants($channelName, $rawPlatformIds);

            if ($accountIds === [] && $platformVariants === []) {
                continue;
            }

            $channelId = $this->resolveChannelId($channelName);
            if ($channelId === null) {
                continue;
            }

            $orConditions = [];
            $params = ['channelId' => $channelId];
            $types = [];

            if ($accountIds !== []) {
                $orConditions[] = 'ca.id IN (:accountIds)';
                $params['accountIds'] = $accountIds;
                $types['accountIds'] = ArrayParameterType::INTEGER;
            }

            if ($platformVariants !== []) {
                $orConditions[] = 'ca.platform_id IN (:platformIds)';
                $params['platformIds'] = $platformVariants;
                $types['platformIds'] = ArrayParameterType::STRING;
            }

            $sql = 'SELECT ca.id, ca.platform_id, ca.name, ca.type, ca.enabled, ca.data, ca.account_id
                    FROM channeled_accounts ca
                    WHERE ca.channel = :channelId AND (' . implode(' OR ', $orConditions) . ')
                    ORDER BY ca.id ASC';

            try {
                $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
            } catch (Throwable) {
                $rows = [];
            }

            if ($rows !== []) {
                $resolved[$channelName] = $rows;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<int, int>
     */
    private function flattenPermittedAccountIds(array $permittedAccountsByChannel): array
    {
        $ids = [];
        foreach ($permittedAccountsByChannel as $accounts) {
            foreach ($accounts as $acc) {
                $id = (int)($acc['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<int, int>
     */
    private function flattenPermittedBaseAccountIds(array $permittedAccountsByChannel): array
    {
        $ids = [];
        foreach ($permittedAccountsByChannel as $accounts) {
            foreach ($accounts as $acc) {
                $id = (int)($acc['account_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<int, string>
     */
    private function flattenPermittedPlatformIds(array $permittedAccountsByChannel): array
    {
        $ids = [];
        foreach ($permittedAccountsByChannel as $accounts) {
            foreach ($accounts as $acc) {
                $pid = trim((string)($acc['platform_id'] ?? ''));
                if ($pid !== '') {
                    $ids[$pid] = $pid;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, mixed> $rawPlatformIds
     * @return array<int, string>
     */
    private function expandPlatformIdVariants(string $channelName, array $rawPlatformIds): array
    {
        $variants = [];
        foreach ($rawPlatformIds as $raw) {
            $trimmed = trim((string)$raw);
            if ($trimmed === '') {
                continue;
            }
            $variants[$trimmed] = $trimmed;

            if ($channelName === 'facebook_marketing') {
                if (str_starts_with($trimmed, 'act_')) {
                    $stripped = substr($trimmed, 4);
                    if ($stripped !== '') {
                        $variants[$stripped] = $stripped;
                    }
                } else {
                    $prefixed = 'act_' . $trimmed;
                    $variants[$prefixed] = $prefixed;
                }
            } elseif ($channelName === 'google_ads') {
                $strippedPrefix = preg_replace('#^customers/#i', '', $trimmed) ?? $trimmed;
                $variants[$strippedPrefix] = $strippedPrefix;
                $digitsOnly = str_replace('-', '', $strippedPrefix);
                if ($digitsOnly !== '') {
                    $variants[$digitsOnly] = $digitsOnly;
                    $variants['customers/' . $digitsOnly] = 'customers/' . $digitsOnly;
                }
            } elseif ($channelName === 'google_search_console') {
                $withoutTrailing = rtrim($trimmed, '/');
                $withTrailing = $withoutTrailing !== '' ? $withoutTrailing . '/' : $trimmed;
                $variants[$withoutTrailing] = $withoutTrailing;
                $variants[$withTrailing] = $withTrailing;
                $md5Raw = md5($trimmed);
                $md5Without = md5($withoutTrailing);
                $md5With = md5($withTrailing);
                $variants[$md5Raw] = $md5Raw;
                $variants[$md5Without] = $md5Without;
                $variants[$md5With] = $md5With;
            } elseif ($channelName === 'google_analytics') {
                if (str_starts_with($trimmed, 'properties/')) {
                    $stripped = substr($trimmed, 11);
                    if ($stripped !== '') {
                        $variants[$stripped] = $stripped;
                    }
                } else {
                    $prefixed = 'properties/' . $trimmed;
                    $variants[$prefixed] = $prefixed;
                }
            }
        }

        return array_values($variants);
    }

    private function assertSupportedEntityType(string $entityType): void
    {
        if (!in_array($entityType, CatalogEntityReference::SUPPORTED_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Catalog entity type '{$entityType}' is not enabled.");
        }
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listAccounts(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $where = ['ca.id IN (:accountIds)'];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $statusUpper = strtoupper(trim($filters['status']));
            if (in_array($statusUpper, ['ENABLED', 'ACTIVE', '1', 'TRUE'], true)) {
                $where[] = 'ca.enabled = :enabledVal';
                $params['enabledVal'] = 1;
            } elseif (in_array($statusUpper, ['DISABLED', 'PAUSED', 'INACTIVE', '0', 'FALSE'], true)) {
                $where[] = 'ca.enabled = :enabledVal';
                $params['enabledVal'] = 0;
            }
        }

        if (isset($filters['type']) && is_string($filters['type']) && trim($filters['type']) !== '') {
            $where[] = 'UPPER(ca.type) = :accountType';
            $params['accountType'] = strtoupper(trim($filters['type']));
        }

        if ($search !== '') {
            $where[] = '(LOWER(ca.name) LIKE :search OR LOWER(ca.platform_id) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(ca.id) FROM channeled_accounts ca WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'                => 'ca.name',
            'platform_id'         => 'ca.platform_id',
            'channel'             => 'ca.channel',
            'updated_at'          => 'ca.updated_at',
            'platform_created_at' => 'ca.platform_created_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'ca.name';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'name';

        $sql = "SELECT ca.*, a.name AS base_account_name
                FROM channeled_accounts ca
                LEFT JOIN accounts a ON a.id = ca.account_id
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, ca.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateAccountRow($row), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listCampaigns(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $where = ['cc.channeled_account_id IN (:accountIds)'];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $where[] = 'cc.channeled_account_id = :parentAccountId';
                $params['parentAccountId'] = $parentRef->getId();
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if (isset($filters['account_id']) && is_numeric($filters['account_id']) && (int)$filters['account_id'] > 0) {
            $where[] = 'cc.channeled_account_id = :explicitAccountId';
            $params['explicitAccountId'] = (int)$filters['account_id'];
        }

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $where[] = 'UPPER(cc.status) = :status';
            $params['status'] = strtoupper(trim($filters['status']));
        }

        if (isset($filters['objective']) && is_string($filters['objective']) && trim($filters['objective']) !== '') {
            $where[] = 'UPPER(cc.objective) = :objective';
            $params['objective'] = strtoupper(trim($filters['objective']));
        }

        if (isset($filters['buying_type']) && is_string($filters['buying_type']) && trim($filters['buying_type']) !== '') {
            $where[] = 'UPPER(cc.buying_type) = :buyingType';
            $params['buyingType'] = strtoupper(trim($filters['buying_type']));
        }

        if ($search !== '') {
            $where[] = '(LOWER(COALESCE(c.name, \'\')) LIKE :search OR LOWER(cc.platform_id) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(cc.id)
             FROM channeled_campaigns cc
             LEFT JOIN campaigns c ON c.id = cc.campaign_id
             WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'        => 'c.name',
            'status'      => 'cc.status',
            'budget'      => 'cc.budget',
            'objective'   => 'cc.objective',
            'buying_type' => 'cc.buying_type',
            'start_date'  => 'c.start_date',
            'end_date'    => 'c.end_date',
            'updated_at'  => 'cc.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'c.name';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'name';

        $sql = "SELECT cc.*,
                       c.name AS campaign_name,
                       c.campaign_id AS external_campaign_id,
                       c.start_date AS campaign_start_date,
                       c.end_date AS campaign_end_date,
                       ca.name AS account_name,
                       ca.platform_id AS account_platform_id,
                       ca.data AS account_data
                FROM channeled_campaigns cc
                LEFT JOIN campaigns c ON c.id = cc.campaign_id
                INNER JOIN channeled_accounts ca ON ca.id = cc.channeled_account_id
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, cc.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateCampaignRow($row), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listAdGroups(
        string $entityType,
        string $requiredChannelName,
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $channelId = $this->resolveChannelId($requiredChannelName);
        if ($channelId === null) {
            return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
        }

        $where = [
            'cag.channeled_account_id IN (:accountIds)',
            'cag.channel = :channelId',
        ];
        $params = [
            'accountIds' => $permittedAccountIds,
            'channelId'  => $channelId,
        ];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $where[] = 'cag.channeled_account_id = :parentAccountId';
                $params['parentAccountId'] = $parentRef->getId();
            } elseif ($parentRef->getEntityType() === 'campaigns') {
                $where[] = 'cag.channeled_campaign_id = :parentCampaignId';
                $params['parentCampaignId'] = $parentRef->getId();
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if (isset($filters['account_id']) && is_numeric($filters['account_id']) && (int)$filters['account_id'] > 0) {
            $where[] = 'cag.channeled_account_id = :explicitAccountId';
            $params['explicitAccountId'] = (int)$filters['account_id'];
        }

        if (isset($filters['campaign_id']) && is_numeric($filters['campaign_id']) && (int)$filters['campaign_id'] > 0) {
            $where[] = 'cag.channeled_campaign_id = :explicitCampaignId';
            $params['explicitCampaignId'] = (int)$filters['campaign_id'];
        }

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $where[] = 'UPPER(cag.status) = :status';
            $params['status'] = strtoupper(trim($filters['status']));
        }

        if (isset($filters['optimization_goal']) && is_string($filters['optimization_goal']) && trim($filters['optimization_goal']) !== '') {
            $where[] = 'UPPER(cag.optimization_goal) = :optimizationGoal';
            $params['optimizationGoal'] = strtoupper(trim($filters['optimization_goal']));
        }

        if (isset($filters['billing_event']) && is_string($filters['billing_event']) && trim($filters['billing_event']) !== '') {
            $where[] = 'UPPER(cag.billing_event) = :billingEvent';
            $params['billingEvent'] = strtoupper(trim($filters['billing_event']));
        }

        if ($search !== '') {
            $where[] = '(LOWER(cag.name) LIKE :search OR LOWER(cag.platform_id) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(cag.id) FROM channeled_ad_groups cag WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'              => 'cag.name',
            'status'            => 'cag.status',
            'optimization_goal' => 'cag.optimization_goal',
            'billing_event'     => 'cag.billing_event',
            'start_date'        => 'cag.start_date',
            'end_date'          => 'cag.end_date',
            'updated_at'        => 'cag.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'cag.name';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'name';

        $sql = "SELECT cag.*,
                       cc.platform_id AS campaign_platform_id,
                       cc.data AS channeled_campaign_data,
                       c.name AS campaign_name,
                       ca.name AS account_name,
                       ca.platform_id AS account_platform_id,
                       ca.data AS account_data
                FROM channeled_ad_groups cag
                INNER JOIN channeled_accounts ca ON ca.id = cag.channeled_account_id
                LEFT JOIN channeled_campaigns cc ON cc.id = cag.channeled_campaign_id
                LEFT JOIN campaigns c ON c.id = COALESCE(cc.campaign_id, cag.campaign_id)
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, cag.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateAdGroupRow($row, $entityType), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listAds(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $where = ['cad.channeled_account_id IN (:accountIds)'];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $where[] = 'cad.channeled_account_id = :parentAccountId';
                $params['parentAccountId'] = $parentRef->getId();
            } elseif ($parentRef->getEntityType() === 'campaigns') {
                $where[] = 'cad.channeled_campaign_id = :parentCampaignId';
                $params['parentCampaignId'] = $parentRef->getId();
            } elseif (in_array($parentRef->getEntityType(), ['ad-sets', 'ad-groups'], true)) {
                $where[] = 'cad.channeled_ad_group_id = :parentAdGroupId';
                $params['parentAdGroupId'] = $parentRef->getId();
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if (isset($filters['account_id']) && is_numeric($filters['account_id']) && (int)$filters['account_id'] > 0) {
            $where[] = 'cad.channeled_account_id = :explicitAccountId';
            $params['explicitAccountId'] = (int)$filters['account_id'];
        }

        if (isset($filters['status']) && is_string($filters['status']) && trim($filters['status']) !== '') {
            $where[] = 'UPPER(cad.status) = :status';
            $params['status'] = strtoupper(trim($filters['status']));
        }

        if ($search !== '') {
            $where[] = '(LOWER(cad.name) LIKE :search OR LOWER(cad.platform_id) LIKE :search OR LOWER(COALESCE(cr.name, \'\')) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(cad.id)
             FROM channeled_ads cad
             LEFT JOIN creatives cr ON cr.id = cad.creative_id
             WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'        => 'cad.name',
            'status'      => 'cad.status',
            'platform_id' => 'cad.platform_id',
            'updated_at'  => 'cad.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'cad.name';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'name';

        $sql = "SELECT cad.*,
                       cr.creative_id AS external_creative_id,
                       cr.name AS creative_name,
                       cr.data AS creative_data,
                       cag.name AS ad_group_name,
                       cag.platform_id AS ad_group_platform_id,
                       cc.platform_id AS campaign_platform_id,
                       cc.data AS channeled_campaign_data,
                       c.name AS campaign_name,
                       ca.name AS account_name,
                       ca.platform_id AS account_platform_id,
                       ca.data AS account_data
                FROM channeled_ads cad
                INNER JOIN channeled_accounts ca ON ca.id = cad.channeled_account_id
                LEFT JOIN creatives cr ON cr.id = cad.creative_id
                LEFT JOIN channeled_ad_groups cag ON cag.id = cad.channeled_ad_group_id
                LEFT JOIN channeled_campaigns cc ON cc.id = cad.channeled_campaign_id
                LEFT JOIN campaigns c ON c.id = cc.campaign_id
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, cad.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateAdRow($row), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileAccount(CatalogEntityReference $ref, array $permittedAccountIds, array $dateRange): ?array
    {
        if (!in_array($ref->getId(), $permittedAccountIds, true)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ca.*, a.name AS base_account_name, a.created_at AS base_created_at, a.updated_at AS base_updated_at
             FROM channeled_accounts ca
             LEFT JOIN accounts a ON a.id = ca.account_id
             WHERE ca.id = :id',
            ['id' => $ref->getId()]
        );

        if (!is_array($row)) {
            return null;
        }

        $channelName = $this->resolveChannelName((int)$row['channel']);
        if ($channelName !== $ref->getChannel()) {
            return null;
        }

        $hydrated = $this->hydrateAccountRow($row);
        $accountId = (int)$row['id'];
        $baseAccountId = !empty($row['account_id']) ? (int)$row['account_id'] : 0;
        $platformId = (string)$row['platform_id'];

        $children = [];

        if (in_array($channelName, ['facebook_marketing', 'google_ads', 'mailchimp'], true)) {
            $campaignCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(id) FROM channeled_campaigns WHERE channeled_account_id = :accountId',
                ['accountId' => $accountId]
            );
            $campaignRows = $this->connection->fetchAllAssociative(
                'SELECT cc.id, cc.platform_id, cc.channel, cc.status, cc.budget, cc.objective, cc.data, c.name AS campaign_name
                 FROM channeled_campaigns cc
                 LEFT JOIN campaigns c ON c.id = cc.campaign_id
                 WHERE cc.channeled_account_id = :accountId
                 ORDER BY cc.updated_at DESC, cc.id ASC
                 LIMIT 10',
                ['accountId' => $accountId]
            );
            $campaignItems = array_map(function (array $cRow) use ($channelName): array {
                $cData = $this->decodeJson($cRow['data'] ?? null);
                $name = $cRow['campaign_name'] ?: ($cData['name'] ?? ($cData['settings']['title'] ?? ('Campaign #' . $cRow['platform_id'])));

                return [
                    'reference'   => CatalogEntityReference::format('channeled', $channelName, 'campaigns', (int)$cRow['id']),
                    'entity_type' => 'campaigns',
                    'id'          => (int)$cRow['id'],
                    'name'        => (string)$name,
                    'platform_id' => (string)$cRow['platform_id'],
                    'status'      => $cRow['status'] ?? 'UNKNOWN',
                ];
            }, $campaignRows);

            $children['campaigns'] = [
                'entity_type' => 'campaigns',
                'label'       => 'Campaigns',
                'total_count' => $campaignCount,
                'items'       => $campaignItems,
            ];
        }

        if (in_array($channelName, ['facebook_marketing', 'google_ads'], true)) {
            $adGroupEntityType = $channelName === 'facebook_marketing' ? 'ad-sets' : 'ad-groups';
            $adGroupLabel = $channelName === 'facebook_marketing' ? 'Ad Sets' : 'Ad Groups';
            $adGroupCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(id) FROM channeled_ad_groups WHERE channeled_account_id = :accountId',
                ['accountId' => $accountId]
            );
            $adGroupRows = $this->connection->fetchAllAssociative(
                'SELECT id, platform_id, name, status
                 FROM channeled_ad_groups
                 WHERE channeled_account_id = :accountId
                 ORDER BY updated_at DESC, id ASC
                 LIMIT 10',
                ['accountId' => $accountId]
            );
            $adGroupItems = array_map(static fn (array $agRow): array => [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, $adGroupEntityType, (int)$agRow['id']),
                'entity_type' => $adGroupEntityType,
                'id'          => (int)$agRow['id'],
                'name'        => (string)$agRow['name'],
                'platform_id' => (string)$agRow['platform_id'],
                'status'      => $agRow['status'] ?? 'UNKNOWN',
            ], $adGroupRows);

            $adsCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(id) FROM channeled_ads WHERE channeled_account_id = :accountId',
                ['accountId' => $accountId]
            );

            $children[$adGroupEntityType] = [
                'entity_type' => $adGroupEntityType,
                'label'       => $adGroupLabel,
                'total_count' => $adGroupCount,
                'items'       => $adGroupItems,
            ];
            $children['ads'] = [
                'entity_type' => 'ads',
                'label'       => 'Ads',
                'total_count' => $adsCount,
                'items'       => [],
            ];
        }

        if (in_array($channelName, ['facebook_organic', 'google_search_console', 'google_analytics'], true)) {
            $pageOr = ['EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.page_id = p.id AND mc.channeled_account_id = :accountId)'];
            $pageParams = ['accountId' => $accountId];
            if ($baseAccountId > 0) {
                $pageOr[] = 'p.account_id = :baseAccountId';
                $pageParams['baseAccountId'] = $baseAccountId;
            }
            if ($platformId !== '') {
                $pageOr[] = 'p.platform_id = :platformId';
                $pageParams['platformId'] = $platformId;
            }
            $pageWhere = implode(' OR ', $pageOr);

            $pageCount = (int)$this->connection->fetchOne(
                "SELECT COUNT(p.id) FROM pages p WHERE {$pageWhere}",
                $pageParams
            );
            $pageRows = $this->connection->fetchAllAssociative(
                "SELECT p.id, p.title, p.url, p.platform_id
                 FROM pages p
                 WHERE {$pageWhere}
                 ORDER BY p.updated_at DESC, p.id ASC
                 LIMIT 10",
                $pageParams
            );
            $pageItems = array_map(static fn (array $pRow): array => [
                'reference'   => CatalogEntityReference::format('base', $channelName, 'pages', (int)$pRow['id']),
                'entity_type' => 'pages',
                'id'          => (int)$pRow['id'],
                'name'        => (string)($pRow['title'] ?: $pRow['url']),
                'platform_id' => (string)($pRow['platform_id'] ?: $pRow['url']),
                'status'      => 'ACTIVE',
            ], $pageRows);

            $children['pages'] = [
                'entity_type' => 'pages',
                'label'       => 'Pages',
                'total_count' => $pageCount,
                'items'       => $pageItems,
            ];
        }

        if ($channelName === 'facebook_organic') {
            $postCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(po.id) FROM posts po WHERE po.channeled_account_id = :accountId',
                ['accountId' => $accountId]
            );
            $postRows = $this->connection->fetchAllAssociative(
                'SELECT po.id, po.post_id, po.data
                 FROM posts po
                 WHERE po.channeled_account_id = :accountId
                 ORDER BY po.updated_at DESC, po.id ASC
                 LIMIT 10',
                ['accountId' => $accountId]
            );
            $postItems = array_map(function (array $poRow) use ($channelName): array {
                $poData = $this->decodeJson($poRow['data'] ?? null);
                $label = $this->extractPostTitle($poData, (string)$poRow['post_id']);

                return [
                    'reference'   => CatalogEntityReference::format('channeled', $channelName, 'posts', (int)$poRow['id']),
                    'entity_type' => 'posts',
                    'id'          => (int)$poRow['id'],
                    'name'        => $label,
                    'platform_id' => (string)$poRow['post_id'],
                    'status'      => 'PUBLISHED',
                ];
            }, $postRows);

            $children['posts'] = [
                'entity_type' => 'posts',
                'label'       => 'Posts',
                'total_count' => $postCount,
                'items'       => $postItems,
            ];
        }

        if ($channelName === 'google_search_console') {
            $queryCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(DISTINCT mc.query_id)
                 FROM metric_configs mc
                 WHERE mc.channeled_account_id = :accountId AND mc.query_id IS NOT NULL',
                ['accountId' => $accountId]
            );
            $queryRows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT q.id, q.query, q.updated_at
                 FROM queries q
                 INNER JOIN metric_configs mc ON mc.query_id = q.id
                 WHERE mc.channeled_account_id = :accountId
                 ORDER BY q.updated_at DESC, q.id ASC
                 LIMIT 10',
                ['accountId' => $accountId]
            );
            $queryItems = array_map(static fn (array $qRow): array => [
                'reference'   => CatalogEntityReference::format('base', $channelName, 'queries', (int)$qRow['id']),
                'entity_type' => 'queries',
                'id'          => (int)$qRow['id'],
                'name'        => (string)$qRow['query'],
                'platform_id' => (string)$qRow['query'],
                'status'      => 'ACTIVE',
            ], $queryRows);

            $children['queries'] = [
                'entity_type' => 'queries',
                'label'       => 'Queries',
                'total_count' => $queryCount,
                'items'       => $queryItems,
            ];
        }

        if (in_array($channelName, ['google_analytics', 'mailchimp'], true)) {
            $eventCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(DISTINCT e.id)
                 FROM events e
                 WHERE EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.event_id = e.id AND mc.channeled_account_id = :accountId)
                    OR EXISTS (SELECT 1 FROM channeled_events ce WHERE ce.event_id = e.id AND ce.channeled_account_id = :accountId)',
                ['accountId' => $accountId]
            );
            $eventRows = $this->connection->fetchAllAssociative(
                'SELECT e.id, e.name
                 FROM events e
                 WHERE EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.event_id = e.id AND mc.channeled_account_id = :accountId)
                    OR EXISTS (SELECT 1 FROM channeled_events ce WHERE ce.event_id = e.id AND ce.channeled_account_id = :accountId)
                 ORDER BY e.name ASC, e.id ASC
                 LIMIT 10',
                ['accountId' => $accountId]
            );
            $eventItems = array_map(static fn (array $eRow): array => [
                'reference'   => CatalogEntityReference::format('base', $channelName, 'events', (int)$eRow['id']),
                'entity_type' => 'events',
                'id'          => (int)$eRow['id'],
                'name'        => (string)$eRow['name'],
                'platform_id' => (string)$eRow['name'],
                'status'      => 'ACTIVE',
            ], $eventRows);

            $children['events'] = [
                'entity_type' => 'events',
                'label'       => 'Events',
                'total_count' => $eventCount,
                'items'       => $eventItems,
            ];
        }

        $sourceRecords = [
            [
                'layer'       => 'channeled',
                'table'       => 'channeled_accounts',
                'id'          => $accountId,
                'platform_id' => (string)$row['platform_id'],
                'channel'     => $channelName,
                'name'        => (string)$row['name'],
            ],
        ];
        if (!empty($row['account_id'])) {
            $sourceRecords[] = [
                'layer' => 'base',
                'table' => 'accounts',
                'id'    => (int)$row['account_id'],
                'name'  => (string)($row['base_account_name'] ?? $row['name']),
            ];
        }

        $metrics = $this->computeEntityMetrics(
            'accounts',
            $accountId,
            $accountId,
            (int)$row['channel'],
            $hydrated['attributes']['currency'] ?? null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => [],
                'children' => $children,
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileCampaign(CatalogEntityReference $ref, array $permittedAccountIds, array $dateRange): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT cc.*,
                    c.name AS campaign_name,
                    c.campaign_id AS external_campaign_id,
                    c.start_date AS campaign_start_date,
                    c.end_date AS campaign_end_date,
                    ca.name AS account_name,
                    ca.platform_id AS account_platform_id,
                    ca.data AS account_data
             FROM channeled_campaigns cc
             LEFT JOIN campaigns c ON c.id = cc.campaign_id
             INNER JOIN channeled_accounts ca ON ca.id = cc.channeled_account_id
             WHERE cc.id = :id AND cc.channeled_account_id IN (:accountIds)',
            [
                'id'         => $ref->getId(),
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $channelName = $this->resolveChannelName((int)$row['channel']);
        if ($channelName !== $ref->getChannel()) {
            return null;
        }

        $hydrated = $this->hydrateCampaignRow($row);
        $campaignId = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];

        $children = [];
        if (in_array($channelName, ['facebook_marketing', 'google_ads'], true)) {
            $adGroupEntityType = $channelName === 'facebook_marketing' ? 'ad-sets' : 'ad-groups';
            $adGroupLabel = $channelName === 'facebook_marketing' ? 'Ad Sets' : 'Ad Groups';

            $adGroupCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(id) FROM channeled_ad_groups WHERE channeled_campaign_id = :campaignId AND channeled_account_id = :accountId',
                ['campaignId' => $campaignId, 'accountId' => $accountId]
            );
            $adGroupRows = $this->connection->fetchAllAssociative(
                'SELECT id, platform_id, name, status, optimization_goal, billing_event
                 FROM channeled_ad_groups
                 WHERE channeled_campaign_id = :campaignId AND channeled_account_id = :accountId
                 ORDER BY updated_at DESC, id ASC
                 LIMIT 10',
                ['campaignId' => $campaignId, 'accountId' => $accountId]
            );
            $adGroupItems = array_map(static fn (array $agRow): array => [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, $adGroupEntityType, (int)$agRow['id']),
                'entity_type' => $adGroupEntityType,
                'id'          => (int)$agRow['id'],
                'name'        => (string)$agRow['name'],
                'platform_id' => (string)$agRow['platform_id'],
                'status'      => $agRow['status'] ?? 'UNKNOWN',
            ], $adGroupRows);

            $adsCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(id) FROM channeled_ads WHERE channeled_campaign_id = :campaignId AND channeled_account_id = :accountId',
                ['campaignId' => $campaignId, 'accountId' => $accountId]
            );
            $adRows = $this->connection->fetchAllAssociative(
                'SELECT id, platform_id, name, status
                 FROM channeled_ads
                 WHERE channeled_campaign_id = :campaignId AND channeled_account_id = :accountId
                 ORDER BY updated_at DESC, id ASC
                 LIMIT 10',
                ['campaignId' => $campaignId, 'accountId' => $accountId]
            );
            $adItems = array_map(static fn (array $adRow): array => [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'ads', (int)$adRow['id']),
                'entity_type' => 'ads',
                'id'          => (int)$adRow['id'],
                'name'        => (string)$adRow['name'],
                'platform_id' => (string)$adRow['platform_id'],
                'status'      => $adRow['status'] ?? 'UNKNOWN',
            ], $adRows);

            $children[$adGroupEntityType] = [
                'entity_type' => $adGroupEntityType,
                'label'       => $adGroupLabel,
                'total_count' => $adGroupCount,
                'items'       => $adGroupItems,
            ];
            $children['ads'] = [
                'entity_type' => 'ads',
                'label'       => 'Ads',
                'total_count' => $adsCount,
                'items'       => $adItems,
            ];
        }

        $sourceRecords = [
            [
                'layer'       => 'channeled',
                'table'       => 'channeled_campaigns',
                'id'          => $campaignId,
                'platform_id' => (string)$row['platform_id'],
                'channel'     => $channelName,
                'name'        => $hydrated['name'],
            ],
        ];
        if (!empty($row['campaign_id'])) {
            $sourceRecords[] = [
                'layer'       => 'base',
                'table'       => 'campaigns',
                'id'          => (int)$row['campaign_id'],
                'platform_id' => (string)($row['external_campaign_id'] ?? $row['platform_id']),
                'name'        => (string)($row['campaign_name'] ?? $hydrated['name']),
            ];
        }

        $metrics = $this->computeEntityMetrics(
            'campaigns',
            $campaignId,
            $accountId,
            (int)$row['channel'],
            $hydrated['attributes']['currency'] ?? null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => $children,
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileAdGroup(CatalogEntityReference $ref, array $permittedAccountIds, array $dateRange): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT cag.*,
                    cc.platform_id AS campaign_platform_id,
                    cc.data AS channeled_campaign_data,
                    c.name AS campaign_name,
                    ca.name AS account_name,
                    ca.platform_id AS account_platform_id,
                    ca.data AS account_data
             FROM channeled_ad_groups cag
             INNER JOIN channeled_accounts ca ON ca.id = cag.channeled_account_id
             LEFT JOIN channeled_campaigns cc ON cc.id = cag.channeled_campaign_id
             LEFT JOIN campaigns c ON c.id = COALESCE(cc.campaign_id, cag.campaign_id)
             WHERE cag.id = :id AND cag.channeled_account_id IN (:accountIds)',
            [
                'id'         => $ref->getId(),
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $channelName = $this->resolveChannelName((int)$row['channel']);
        if ($channelName !== $ref->getChannel()) {
            return null;
        }

        $hydrated = $this->hydrateAdGroupRow($row, $ref->getEntityType());
        $adGroupId = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];

        $adsCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(id) FROM channeled_ads WHERE channeled_ad_group_id = :adGroupId AND channeled_account_id = :accountId',
            ['adGroupId' => $adGroupId, 'accountId' => $accountId]
        );
        $adRows = $this->connection->fetchAllAssociative(
            'SELECT id, platform_id, name, status
             FROM channeled_ads
             WHERE channeled_ad_group_id = :adGroupId AND channeled_account_id = :accountId
             ORDER BY updated_at DESC, id ASC
             LIMIT 10',
            ['adGroupId' => $adGroupId, 'accountId' => $accountId]
        );
        $adItems = array_map(static fn (array $adRow): array => [
            'reference'   => CatalogEntityReference::format('channeled', $channelName, 'ads', (int)$adRow['id']),
            'entity_type' => 'ads',
            'id'          => (int)$adRow['id'],
            'name'        => (string)$adRow['name'],
            'platform_id' => (string)$adRow['platform_id'],
            'status'      => $adRow['status'] ?? 'UNKNOWN',
        ], $adRows);

        $sourceRecords = [
            [
                'layer'       => 'channeled',
                'table'       => 'channeled_ad_groups',
                'id'          => $adGroupId,
                'platform_id' => (string)$row['platform_id'],
                'channel'     => $channelName,
                'name'        => $hydrated['name'],
            ],
        ];

        $metrics = $this->computeEntityMetrics(
            $ref->getEntityType(),
            $adGroupId,
            $accountId,
            (int)$row['channel'],
            $hydrated['attributes']['currency'] ?? null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => [
                    'ads' => [
                        'entity_type' => 'ads',
                        'label'       => 'Ads',
                        'total_count' => $adsCount,
                        'items'       => $adItems,
                    ],
                ],
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileAd(CatalogEntityReference $ref, array $permittedAccountIds, array $dateRange): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT cad.*,
                    cr.creative_id AS external_creative_id,
                    cr.name AS creative_name,
                    cr.data AS creative_data,
                    cag.name AS ad_group_name,
                    cag.platform_id AS ad_group_platform_id,
                    cc.platform_id AS campaign_platform_id,
                    cc.data AS channeled_campaign_data,
                    c.name AS campaign_name,
                    ca.name AS account_name,
                    ca.platform_id AS account_platform_id,
                    ca.data AS account_data
             FROM channeled_ads cad
             INNER JOIN channeled_accounts ca ON ca.id = cad.channeled_account_id
             LEFT JOIN creatives cr ON cr.id = cad.creative_id
             LEFT JOIN channeled_ad_groups cag ON cag.id = cad.channeled_ad_group_id
             LEFT JOIN channeled_campaigns cc ON cc.id = cad.channeled_campaign_id
             LEFT JOIN campaigns c ON c.id = cc.campaign_id
             WHERE cad.id = :id AND cad.channeled_account_id IN (:accountIds)',
            [
                'id'         => $ref->getId(),
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $channelName = $this->resolveChannelName((int)$row['channel']);
        if ($channelName !== $ref->getChannel()) {
            return null;
        }

        $hydrated = $this->hydrateAdRow($row);
        $adId = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];

        $sourceRecords = [
            [
                'layer'       => 'channeled',
                'table'       => 'channeled_ads',
                'id'          => $adId,
                'platform_id' => (string)$row['platform_id'],
                'channel'     => $channelName,
                'name'        => $hydrated['name'],
            ],
        ];
        if (!empty($row['creative_id'])) {
            $sourceRecords[] = [
                'layer'       => 'base',
                'table'       => 'creatives',
                'id'          => (int)$row['creative_id'],
                'platform_id' => (string)($row['external_creative_id'] ?? ''),
                'name'        => (string)($row['creative_name'] ?? 'Creative #' . $row['creative_id']),
            ];
        }

        $metrics = $this->computeEntityMetrics(
            'ads',
            $adId,
            $accountId,
            (int)$row['channel'],
            $hydrated['attributes']['currency'] ?? null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => [],
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAccountRow(array $row): array
    {
        $id = (int)$row['id'];
        $channelName = $this->resolveChannelName((int)$row['channel']);
        $data = $this->decodeJson($row['data'] ?? null);
        $enabled = (bool)($row['enabled'] ?? true);
        $currency = $this->extractCurrency($data);
        $timezone = $this->extractTimezone($data);

        return [
            'reference'         => CatalogEntityReference::format('channeled', $channelName, 'accounts', $id),
            'id'                => $id,
            'owning_account_id' => $id,
            'entity_type'       => 'accounts',
            'name'              => (string)($row['name'] ?? ('Account #' . $row['platform_id'])),
            'platform_id'       => (string)$row['platform_id'],
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => $enabled ? 'ENABLED' : 'DISABLED',
            'attributes'        => [
                'type'              => (string)($row['type'] ?? 'ADVERTISING'),
                'enabled'           => $enabled,
                'currency'          => $currency,
                'timezone'          => $timezone,
                'base_account_name' => $row['base_account_name'] ?? null,
            ],
            'provenance'        => $this->buildProvenance('channeled', 'channeled_accounts', $row),
            'time_semantics'    => [
                'Entity timestamps reflect source platform snapshots; daily performance metrics use account-timezone reporting days.',
            ],
            'relationships'     => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateCampaignRow(array $row): array
    {
        $id = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];
        $channelName = $this->resolveChannelName((int)$row['channel']);
        $data = $this->decodeJson($row['data'] ?? null);
        $accountData = $this->decodeJson($row['account_data'] ?? null);

        $name = $row['campaign_name'] ?? $data['name'] ?? ($data['settings']['title'] ?? ($data['settings']['subject_line'] ?? ('Campaign #' . $row['platform_id'])));
        $currency = $this->extractCurrency($data) ?? $this->extractCurrency($accountData);
        $timezone = $this->extractTimezone($data) ?? $this->extractTimezone($accountData);

        $startDate = $this->normalizeDateString($row['campaign_start_date'] ?? ($data['start_date'] ?? $data['start_time'] ?? $data['send_time'] ?? null));
        $endDate = $this->normalizeDateString($row['campaign_end_date'] ?? ($data['end_date'] ?? $data['stop_time'] ?? null));
        $sendTime = isset($data['send_time']) && is_string($data['send_time']) && trim($data['send_time']) !== ''
            ? trim($data['send_time'])
            : null;

        return [
            'reference'         => CatalogEntityReference::format('channeled', $channelName, 'campaigns', $id),
            'id'                => $id,
            'owning_account_id' => $accountId,
            'entity_type'       => 'campaigns',
            'name'              => (string)$name,
            'platform_id'       => (string)$row['platform_id'],
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => (string)($row['status'] ?? 'UNKNOWN'),
            'attributes'        => [
                'budget'       => isset($row['budget']) ? (float)$row['budget'] : 0.0,
                'currency'     => $currency,
                'timezone'     => $timezone,
                'objective'    => $row['objective'] ?? null,
                'buying_type'  => $row['buying_type'] ?? null,
                'type'         => $row['type'] ?? 'regular',
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'send_time'    => $sendTime,
                'subject_line' => $data['settings']['subject_line'] ?? $data['subject_line'] ?? null,
            ],
            'provenance'        => $this->buildProvenance('channeled', 'channeled_campaigns', $row),
            'time_semantics'    => [
                'Campaign start/end dates represent scheduled delivery windows; performance metrics are aggregated by daily reporting date.',
            ],
            'relationships'     => [
                'account' => [
                    'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $accountId),
                    'entity_type' => 'accounts',
                    'id'          => $accountId,
                    'name'        => (string)($row['account_name'] ?? ('Account #' . $row['account_platform_id'])),
                    'platform_id' => (string)($row['account_platform_id'] ?? ''),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAdGroupRow(array $row, string $entityType): array
    {
        $id = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];
        $channelName = $this->resolveChannelName((int)$row['channel']);
        $data = $this->decodeJson($row['data'] ?? null);
        $accountData = $this->decodeJson($row['account_data'] ?? null);
        $targeting = $this->decodeJson($row['targeting'] ?? null);

        $currency = $this->extractCurrency($data) ?? $this->extractCurrency($accountData);
        $timezone = $this->extractTimezone($data) ?? $this->extractTimezone($accountData);

        $startDate = $this->normalizeDateString($row['start_date'] ?? null);
        $endDate = $this->normalizeDateString($row['end_date'] ?? null);

        $relationships = [
            'account' => [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $accountId),
                'entity_type' => 'accounts',
                'id'          => $accountId,
                'name'        => (string)($row['account_name'] ?? ('Account #' . $row['account_platform_id'])),
                'platform_id' => (string)($row['account_platform_id'] ?? ''),
            ],
        ];

        if (!empty($row['channeled_campaign_id'])) {
            $campId = (int)$row['channeled_campaign_id'];
            $campData = $this->decodeJson($row['channeled_campaign_data'] ?? null);
            $campName = $row['campaign_name'] ?: ($campData['name'] ?? ('Campaign #' . ($row['campaign_platform_id'] ?? $campId)));
            $relationships['campaign'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'campaigns', $campId),
                'entity_type' => 'campaigns',
                'id'          => $campId,
                'name'        => (string)$campName,
                'platform_id' => (string)($row['campaign_platform_id'] ?? ''),
            ];
        }

        return [
            'reference'         => CatalogEntityReference::format('channeled', $channelName, $entityType, $id),
            'id'                => $id,
            'owning_account_id' => $accountId,
            'entity_type'       => $entityType,
            'name'              => (string)($row['name'] ?? ('Ad Group #' . $row['platform_id'])),
            'platform_id'       => (string)$row['platform_id'],
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => (string)($row['status'] ?? 'UNKNOWN'),
            'attributes'        => [
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'billing_event'     => $row['billing_event'] ?? null,
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'currency'          => $currency,
                'timezone'          => $timezone,
                'targeting_summary' => $this->summarizeTargeting($targeting),
            ],
            'provenance'        => $this->buildProvenance('channeled', 'channeled_ad_groups', $row),
            'time_semantics'    => [
                'Start/end dates reflect ad set/group schedule windows; performance metrics use daily reporting grain.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAdRow(array $row): array
    {
        $id = (int)$row['id'];
        $accountId = (int)$row['channeled_account_id'];
        $channelName = $this->resolveChannelName((int)$row['channel']);
        $data = $this->decodeJson($row['data'] ?? null);
        $accountData = $this->decodeJson($row['account_data'] ?? null);
        $creativeData = $this->decodeJson($row['creative_data'] ?? null);

        $currency = $this->extractCurrency($data) ?? $this->extractCurrency($accountData);
        $timezone = $this->extractTimezone($data) ?? $this->extractTimezone($accountData);

        $relationships = [
            'account' => [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $accountId),
                'entity_type' => 'accounts',
                'id'          => $accountId,
                'name'        => (string)($row['account_name'] ?? ('Account #' . $row['account_platform_id'])),
                'platform_id' => (string)($row['account_platform_id'] ?? ''),
            ],
        ];

        if (!empty($row['channeled_campaign_id'])) {
            $campId = (int)$row['channeled_campaign_id'];
            $campData = $this->decodeJson($row['channeled_campaign_data'] ?? null);
            $campName = $row['campaign_name'] ?: ($campData['name'] ?? ('Campaign #' . ($row['campaign_platform_id'] ?? $campId)));
            $relationships['campaign'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'campaigns', $campId),
                'entity_type' => 'campaigns',
                'id'          => $campId,
                'name'        => (string)$campName,
                'platform_id' => (string)($row['campaign_platform_id'] ?? ''),
            ];
        }

        if (!empty($row['channeled_ad_group_id'])) {
            $agId = (int)$row['channeled_ad_group_id'];
            $agType = $channelName === 'facebook_marketing' ? 'ad-sets' : 'ad-groups';
            $relationships['ad_group'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, $agType, $agId),
                'entity_type' => $agType,
                'id'          => $agId,
                'name'        => (string)($row['ad_group_name'] ?? ('Ad Group #' . ($row['ad_group_platform_id'] ?? $agId))),
                'platform_id' => (string)($row['ad_group_platform_id'] ?? ''),
            ];
        }

        $previewUrl = $creativeData['image_url']
            ?? $creativeData['thumbnail_url']
            ?? $data['preview_shareable_link']
            ?? null;

        return [
            'reference'         => CatalogEntityReference::format('channeled', $channelName, 'ads', $id),
            'id'                => $id,
            'owning_account_id' => $accountId,
            'entity_type'       => 'ads',
            'name'              => (string)($row['name'] ?? ('Ad #' . $row['platform_id'])),
            'platform_id'       => (string)$row['platform_id'],
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => (string)($row['status'] ?? 'UNKNOWN'),
            'attributes'        => [
                'creative_id'          => !empty($row['creative_id']) ? (int)$row['creative_id'] : null,
                'external_creative_id' => $row['external_creative_id'] ?? null,
                'creative_name'        => $row['creative_name'] ?? null,
                'headline'             => $creativeData['headline'] ?? $creativeData['title'] ?? null,
                'body'                 => $creativeData['body'] ?? $creativeData['description'] ?? null,
                'preview_url'          => is_string($previewUrl) ? $previewUrl : null,
                'currency'             => $currency,
                'timezone'             => $timezone,
            ],
            'provenance'        => $this->buildProvenance('channeled', 'channeled_ads', $row),
            'time_semantics'    => [
                'Ad creation and sync timestamps reflect platform snapshots; performance metrics are aggregated by daily reporting date.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listPages(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $baseAccountIds = $this->flattenPermittedBaseAccountIds($permittedAccountsByChannel);
        $platformIds = $this->flattenPermittedPlatformIds($permittedAccountsByChannel);

        $scopeOr = ['EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.page_id = p.id AND mc.channeled_account_id IN (:accountIds))'];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($baseAccountIds !== []) {
            $scopeOr[] = 'p.account_id IN (:baseAccountIds)';
            $params['baseAccountIds'] = $baseAccountIds;
            $types['baseAccountIds'] = ArrayParameterType::INTEGER;
        }
        if ($platformIds !== []) {
            $scopeOr[] = 'p.platform_id IN (:platformIds)';
            $params['platformIds'] = $platformIds;
            $types['platformIds'] = ArrayParameterType::STRING;
        }

        $where = ['(' . implode(' OR ', $scopeOr) . ')'];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $parentAccId = $parentRef->getId();
                if (!in_array($parentAccId, $permittedAccountIds, true)) {
                    return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
                }
                $parentAccRow = $this->findPermittedAccountRow($permittedAccountsByChannel, $parentAccId);
                $parentOr = ['EXISTS (SELECT 1 FROM metric_configs mc2 WHERE mc2.page_id = p.id AND mc2.channeled_account_id = :parentAccountId)'];
                $params['parentAccountId'] = $parentAccId;
                if ($parentAccRow !== null) {
                    if (!empty($parentAccRow['account_id'])) {
                        $parentOr[] = 'p.account_id = :parentBaseAccountId';
                        $params['parentBaseAccountId'] = (int)$parentAccRow['account_id'];
                    }
                    if (!empty($parentAccRow['platform_id'])) {
                        $parentOr[] = 'p.platform_id = :parentPlatformId';
                        $params['parentPlatformId'] = (string)$parentAccRow['platform_id'];
                    }
                }
                $where[] = '(' . implode(' OR ', $parentOr) . ')';
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if ($search !== '') {
            $where[] = '(LOWER(COALESCE(p.title, \'\')) LIKE :search OR LOWER(p.url) LIKE :search OR LOWER(COALESCE(p.hostname, \'\')) LIKE :search OR LOWER(p.platform_id) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(p.id) FROM pages p WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'       => 'COALESCE(NULLIF(p.title, \'\'), p.url)',
            'title'      => 'COALESCE(NULLIF(p.title, \'\'), p.url)',
            'url'        => 'p.url',
            'hostname'   => 'p.hostname',
            'updated_at' => 'p.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'COALESCE(NULLIF(p.title, \'\'), p.url)';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'title';

        $sql = "SELECT p.*
                FROM pages p
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, p.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydratePageRow($row, $permittedAccountsByChannel, $permittedAccountIds), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profilePage(
        CatalogEntityReference $ref,
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $dateRange
    ): ?array {
        $baseAccountIds = $this->flattenPermittedBaseAccountIds($permittedAccountsByChannel);
        $platformIds = $this->flattenPermittedPlatformIds($permittedAccountsByChannel);

        $scopeOr = ['EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.page_id = p.id AND mc.channeled_account_id IN (:accountIds))'];
        $params = [
            'id'         => $ref->getId(),
            'accountIds' => $permittedAccountIds,
        ];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($baseAccountIds !== []) {
            $scopeOr[] = 'p.account_id IN (:baseAccountIds)';
            $params['baseAccountIds'] = $baseAccountIds;
            $types['baseAccountIds'] = ArrayParameterType::INTEGER;
        }
        if ($platformIds !== []) {
            $scopeOr[] = 'p.platform_id IN (:platformIds)';
            $params['platformIds'] = $platformIds;
            $types['platformIds'] = ArrayParameterType::STRING;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT p.* FROM pages p WHERE p.id = :id AND (' . implode(' OR ', $scopeOr) . ')',
            $params,
            $types
        );

        if (!is_array($row)) {
            return null;
        }

        $hydrated = $this->hydratePageRow($row, $permittedAccountsByChannel, $permittedAccountIds, $ref->getChannel());
        $pageId = (int)$row['id'];
        $channelName = (string)$hydrated['channel'];
        $owningAccountId = (int)($hydrated['owning_account_id'] ?? 0);

        $children = [];
        if ($channelName === 'facebook_organic') {
            $postCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(po.id) FROM posts po WHERE po.page_id = :pageId',
                ['pageId' => $pageId]
            );
            $postRows = $this->connection->fetchAllAssociative(
                'SELECT po.id, po.post_id, po.data
                 FROM posts po
                 WHERE po.page_id = :pageId
                 ORDER BY po.updated_at DESC, po.id ASC
                 LIMIT 10',
                ['pageId' => $pageId]
            );
            $postItems = array_map(function (array $poRow) use ($channelName): array {
                $poData = $this->decodeJson($poRow['data'] ?? null);
                $label = $this->extractPostTitle($poData, (string)$poRow['post_id']);

                return [
                    'reference'   => CatalogEntityReference::format('channeled', $channelName, 'posts', (int)$poRow['id']),
                    'entity_type' => 'posts',
                    'id'          => (int)$poRow['id'],
                    'name'        => $label,
                    'platform_id' => (string)$poRow['post_id'],
                    'status'      => 'PUBLISHED',
                ];
            }, $postRows);

            $children['posts'] = [
                'entity_type' => 'posts',
                'label'       => 'Posts',
                'total_count' => $postCount,
                'items'       => $postItems,
            ];
        } elseif ($channelName === 'google_search_console') {
            $queryCount = (int)$this->connection->fetchOne(
                'SELECT COUNT(DISTINCT mc.query_id)
                 FROM metric_configs mc
                 WHERE mc.page_id = :pageId AND mc.channeled_account_id IN (:accountIds) AND mc.query_id IS NOT NULL',
                ['pageId' => $pageId, 'accountIds' => $permittedAccountIds],
                ['accountIds' => ArrayParameterType::INTEGER]
            );
            $queryRows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT q.id, q.query, q.updated_at
                 FROM queries q
                 INNER JOIN metric_configs mc ON mc.query_id = q.id
                 WHERE mc.page_id = :pageId AND mc.channeled_account_id IN (:accountIds)
                 ORDER BY q.updated_at DESC, q.id ASC
                 LIMIT 10',
                ['pageId' => $pageId, 'accountIds' => $permittedAccountIds],
                ['accountIds' => ArrayParameterType::INTEGER]
            );
            $queryItems = array_map(static fn (array $qRow): array => [
                'reference'   => CatalogEntityReference::format('base', $channelName, 'queries', (int)$qRow['id']),
                'entity_type' => 'queries',
                'id'          => (int)$qRow['id'],
                'name'        => (string)$qRow['query'],
                'platform_id' => (string)$qRow['query'],
                'status'      => 'ACTIVE',
            ], $queryRows);

            $children['queries'] = [
                'entity_type' => 'queries',
                'label'       => 'Queries',
                'total_count' => $queryCount,
                'items'       => $queryItems,
            ];
        }

        $sourceRecords = [
            [
                'layer'       => 'base',
                'table'       => 'pages',
                'id'          => $pageId,
                'platform_id' => (string)($row['platform_id'] ?? $row['url'] ?? ''),
                'channel'     => $channelName,
                'name'        => $hydrated['name'],
            ],
        ];

        $metrics = $this->computeScopedEntityMetrics(
            'pages',
            $pageId,
            $owningAccountId > 0 ? [$owningAccountId] : $permittedAccountIds,
            $this->resolveChannelId($channelName),
            null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => $children,
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listPosts(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $baseAccountIds = $this->flattenPermittedBaseAccountIds($permittedAccountsByChannel);
        $platformIds = $this->flattenPermittedPlatformIds($permittedAccountsByChannel);

        $scopeOr = [
            'po.channeled_account_id IN (:accountIds)',
            'EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.post_id = po.id AND mc.channeled_account_id IN (:accountIds))',
        ];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($baseAccountIds !== []) {
            $scopeOr[] = 'po.account_id IN (:baseAccountIds)';
            $params['baseAccountIds'] = $baseAccountIds;
            $types['baseAccountIds'] = ArrayParameterType::INTEGER;
        }
        if ($platformIds !== []) {
            $scopeOr[] = 'EXISTS (SELECT 1 FROM pages p2 WHERE p2.id = po.page_id AND p2.platform_id IN (:platformIds))';
            $params['platformIds'] = $platformIds;
            $types['platformIds'] = ArrayParameterType::STRING;
        }

        $where = ['(' . implode(' OR ', $scopeOr) . ')'];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $where[] = 'po.channeled_account_id = :parentAccountId';
                $params['parentAccountId'] = $parentRef->getId();
            } elseif ($parentRef->getEntityType() === 'pages') {
                $where[] = 'po.page_id = :parentPageId';
                $params['parentPageId'] = $parentRef->getId();
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if ($search !== '') {
            $where[] = '(LOWER(po.post_id) LIKE :search OR LOWER(COALESCE(po.data, \'\')) LIKE :search)';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(po.id) FROM posts po WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'                => 'po.updated_at',
            'platform_created_at' => 'po.created_at',
            'post_id'             => 'po.post_id',
            'updated_at'          => 'po.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'po.updated_at';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'updated_at';

        $sql = "SELECT po.*,
                       p.title AS page_title,
                       p.url AS page_url,
                       p.platform_id AS page_platform_id,
                       ca.name AS account_name,
                       ca.platform_id AS account_platform_id
                FROM posts po
                LEFT JOIN pages p ON p.id = po.page_id
                LEFT JOIN channeled_accounts ca ON ca.id = po.channeled_account_id
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, po.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydratePostRow($row), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profilePost(
        CatalogEntityReference $ref,
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $dateRange
    ): ?array {
        $baseAccountIds = $this->flattenPermittedBaseAccountIds($permittedAccountsByChannel);
        $platformIds = $this->flattenPermittedPlatformIds($permittedAccountsByChannel);

        $scopeOr = [
            'po.channeled_account_id IN (:accountIds)',
            'EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.post_id = po.id AND mc.channeled_account_id IN (:accountIds))',
        ];
        $params = [
            'id'         => $ref->getId(),
            'accountIds' => $permittedAccountIds,
        ];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($baseAccountIds !== []) {
            $scopeOr[] = 'po.account_id IN (:baseAccountIds)';
            $params['baseAccountIds'] = $baseAccountIds;
            $types['baseAccountIds'] = ArrayParameterType::INTEGER;
        }
        if ($platformIds !== []) {
            $scopeOr[] = 'EXISTS (SELECT 1 FROM pages p2 WHERE p2.id = po.page_id AND p2.platform_id IN (:platformIds))';
            $params['platformIds'] = $platformIds;
            $types['platformIds'] = ArrayParameterType::STRING;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT po.*,
                    p.title AS page_title,
                    p.url AS page_url,
                    p.platform_id AS page_platform_id,
                    ca.name AS account_name,
                    ca.platform_id AS account_platform_id
             FROM posts po
             LEFT JOIN pages p ON p.id = po.page_id
             LEFT JOIN channeled_accounts ca ON ca.id = po.channeled_account_id
             WHERE po.id = :id AND (' . implode(' OR ', $scopeOr) . ')',
            $params,
            $types
        );

        if (!is_array($row)) {
            return null;
        }

        $hydrated = $this->hydratePostRow($row);
        $postId = (int)$row['id'];
        $owningAccountId = (int)($hydrated['owning_account_id'] ?? 0);

        $sourceRecords = [
            [
                'layer'       => 'channeled',
                'table'       => 'posts',
                'id'          => $postId,
                'platform_id' => (string)$row['post_id'],
                'channel'     => 'facebook_organic',
                'name'        => $hydrated['name'],
            ],
        ];

        $metrics = $this->computeScopedEntityMetrics(
            'posts',
            $postId,
            $owningAccountId > 0 ? [$owningAccountId] : $permittedAccountIds,
            $this->resolveChannelId('facebook_organic'),
            null,
            $dateRange,
            true // allow lifetime period metrics for organic posts
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => [],
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listQueries(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $mcWhere = ['mc.query_id = q.id', 'mc.channeled_account_id IN (:accountIds)'];
        $params = ['accountIds' => $permittedAccountIds];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                $mcWhere[] = 'mc.channeled_account_id = :parentAccountId';
                $params['parentAccountId'] = $parentRef->getId();
            } elseif ($parentRef->getEntityType() === 'pages') {
                $mcWhere[] = 'mc.page_id = :parentPageId';
                $params['parentPageId'] = $parentRef->getId();
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        if (isset($filters['account_id']) && is_numeric($filters['account_id']) && (int)$filters['account_id'] > 0) {
            $mcWhere[] = 'mc.channeled_account_id = :explicitAccountId';
            $params['explicitAccountId'] = (int)$filters['account_id'];
        }

        $searchType = isset($filters['search_type']) && is_string($filters['search_type']) && trim($filters['search_type']) !== ''
            ? strtolower(trim($filters['search_type']))
            : null;
        if ($searchType !== null) {
            $params['searchType'] = $searchType;
            if ($searchType === 'web') {
                $mcWhere[] = "(mc.dimension_set_id IS NULL OR EXISTS (
                    SELECT 1 FROM dimension_set_items dsi
                    INNER JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                    INNER JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                    WHERE dsi.dimension_set_id = mc.dimension_set_id
                      AND LOWER(dk.name) = 'search_type'
                      AND LOWER(dv.value) = :searchType
                ))";
            } else {
                $mcWhere[] = "EXISTS (
                    SELECT 1 FROM dimension_set_items dsi
                    INNER JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                    INNER JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                    WHERE dsi.dimension_set_id = mc.dimension_set_id
                      AND LOWER(dk.name) = 'search_type'
                      AND LOWER(dv.value) = :searchType
                )";
            }
        }

        $where = ['EXISTS (SELECT 1 FROM metric_configs mc WHERE ' . implode(' AND ', $mcWhere) . ')'];

        if ($search !== '') {
            $where[] = 'LOWER(q.query) LIKE :search';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(q.id) FROM queries q WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'       => 'q.query',
            'query'      => 'q.query',
            'updated_at' => 'q.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'q.query';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'query';

        $sql = "SELECT q.*
                FROM queries q
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, q.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateQueryRow($row, $permittedAccountsByChannel, $permittedAccountIds, $searchType), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function profileQuery(
        CatalogEntityReference $ref,
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $dateRange,
        array $filters = []
    ): ?array {
        $searchType = isset($filters['search_type']) && is_string($filters['search_type']) && trim($filters['search_type']) !== ''
            ? strtolower(trim($filters['search_type']))
            : null;

        $row = $this->connection->fetchAssociative(
            'SELECT q.*
             FROM queries q
             WHERE q.id = :id
               AND EXISTS (
                   SELECT 1 FROM metric_configs mc
                   WHERE mc.query_id = q.id AND mc.channeled_account_id IN (:accountIds)
               )',
            [
                'id'         => $ref->getId(),
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $hydrated = $this->hydrateQueryRow($row, $permittedAccountsByChannel, $permittedAccountIds, $searchType, $ref->getChannel());
        $queryId = (int)$row['id'];
        $channelName = (string)$hydrated['channel'];
        $owningAccountId = (int)($hydrated['owning_account_id'] ?? 0);

        // Related pages where this query appeared
        $pageRows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT p.id, p.title, p.url, p.platform_id
             FROM pages p
             INNER JOIN metric_configs mc ON mc.page_id = p.id
             WHERE mc.query_id = :queryId AND mc.channeled_account_id IN (:accountIds)
             ORDER BY p.id ASC
             LIMIT 10',
            [
                'queryId'    => $queryId,
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );
        $pageItems = array_map(static fn (array $pRow): array => [
            'reference'   => CatalogEntityReference::format('base', $channelName, 'pages', (int)$pRow['id']),
            'entity_type' => 'pages',
            'id'          => (int)$pRow['id'],
            'name'        => (string)($pRow['title'] ?: $pRow['url']),
            'platform_id' => (string)($pRow['platform_id'] ?: $pRow['url']),
            'status'      => 'ACTIVE',
        ], $pageRows);

        $sourceRecords = [
            [
                'layer'       => 'base',
                'table'       => 'queries',
                'id'          => $queryId,
                'platform_id' => (string)$row['query'],
                'channel'     => $channelName,
                'name'        => (string)$row['query'],
            ],
        ];

        $metrics = $this->computeScopedEntityMetrics(
            'queries',
            $queryId,
            $owningAccountId > 0 ? [$owningAccountId] : $permittedAccountIds,
            $this->resolveChannelId($channelName),
            null,
            $dateRange,
            false,
            $searchType
        );

        $children = [];
        if ($pageItems !== []) {
            $children['pages'] = [
                'entity_type' => 'pages',
                'label'       => 'Landing Pages',
                'total_count' => count($pageItems),
                'items'       => $pageItems,
            ];
        }

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => $children,
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listEvents(
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $scopeAccounts = $permittedAccountIds;
        $parentRef = CatalogEntityReference::tryParse(is_string($filters['parent_reference'] ?? null) ? $filters['parent_reference'] : null);
        if ($parentRef !== null) {
            if ($parentRef->getEntityType() === 'accounts') {
                if (!in_array($parentRef->getId(), $permittedAccountIds, true)) {
                    return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
                }
                $scopeAccounts = [$parentRef->getId()];
            } else {
                return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
            }
        }

        $where = [
            '(EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.event_id = e.id AND mc.channeled_account_id IN (:accountIds))
              OR EXISTS (SELECT 1 FROM channeled_events ce WHERE ce.event_id = e.id AND ce.channeled_account_id IN (:accountIds)))',
        ];
        $params = ['accountIds' => $scopeAccounts];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($search !== '') {
            $where[] = 'LOWER(e.name) LIKE :search';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(e.id) FROM events e WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'       => 'e.name',
            'updated_at' => 'e.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'e.name';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'name';

        $sql = "SELECT e.*
                FROM events e
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, e.id ASC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateEventRow($row, $permittedAccountsByChannel, $scopeAccounts), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileEvent(
        CatalogEntityReference $ref,
        array $permittedAccountIds,
        array $permittedAccountsByChannel,
        array $dateRange
    ): ?array {
        $row = $this->connection->fetchAssociative(
            'SELECT e.*
             FROM events e
             WHERE e.id = :id
               AND (
                   EXISTS (SELECT 1 FROM metric_configs mc WHERE mc.event_id = e.id AND mc.channeled_account_id IN (:accountIds))
                   OR EXISTS (SELECT 1 FROM channeled_events ce WHERE ce.event_id = e.id AND ce.channeled_account_id IN (:accountIds))
               )',
            [
                'id'         => $ref->getId(),
                'accountIds' => $permittedAccountIds,
            ],
            [
                'accountIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $hydrated = $this->hydrateEventRow($row, $permittedAccountsByChannel, $permittedAccountIds, $ref->getChannel());
        $eventId = (int)$row['id'];
        $channelName = (string)$hydrated['channel'];
        $owningAccountId = (int)($hydrated['owning_account_id'] ?? 0);

        $occurrenceCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(ce.id) FROM channeled_events ce WHERE ce.event_id = :eventId AND ce.channeled_account_id IN (:accountIds)',
            ['eventId' => $eventId, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );

        $sourceRecords = [
            [
                'layer'       => 'base',
                'table'       => 'events',
                'id'          => $eventId,
                'platform_id' => (string)$row['name'],
                'channel'     => $channelName,
                'name'        => (string)$row['name'],
            ],
        ];
        if ($occurrenceCount > 0) {
            $sourceRecords[] = [
                'layer'       => 'channeled',
                'table'       => 'channeled_events',
                'id'          => $eventId,
                'platform_id' => (string)$row['name'],
                'channel'     => $channelName,
                'name'        => sprintf('%d recorded event occurrences', $occurrenceCount),
            ];
        }

        $metrics = $this->computeScopedEntityMetrics(
            'events',
            $eventId,
            $owningAccountId > 0 ? [$owningAccountId] : $permittedAccountIds,
            $this->resolveChannelId($channelName),
            null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => [],
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $authorizedScope
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listCustomers(
        array $authorizedScope,
        array $permittedAccountsByChannel,
        ?string $channelFilter,
        array $filters,
        string $search,
        string $requestedSort,
        string $requestedDir,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $permittedCustomerChannels = $this->resolvePermittedCustomerChannels($authorizedScope, $channelFilter);
        if ($permittedCustomerChannels === []) {
            return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
        }

        $channelIds = [];
        foreach ($permittedCustomerChannels as $chName) {
            $cid = $this->resolveChannelId($chName);
            if ($cid !== null) {
                $channelIds[] = $cid;
            }
        }
        if ($channelIds === []) {
            return $this->emptyListResponse($page, $perPage, $requestedSort, $requestedDir, $permittedAccountsByChannel);
        }

        $where = ['EXISTS (SELECT 1 FROM channeled_customers cc WHERE cc.customer_id = c.id AND cc.channel IN (:channelIds))'];
        $params = ['channelIds' => $channelIds];
        $types = ['channelIds' => ArrayParameterType::INTEGER];

        if ($search !== '') {
            $where[] = '(LOWER(c.email) LIKE :search OR EXISTS (SELECT 1 FROM channeled_customers cc2 WHERE cc2.customer_id = c.id AND cc2.channel IN (:channelIds) AND (LOWER(cc2.platform_id) LIKE :search OR LOWER(COALESCE(cc2.data, \'\')) LIKE :search)))';
            $params['search'] = '%' . strtolower($search) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)$this->connection->fetchOne(
            "SELECT COUNT(c.id) FROM customers c WHERE {$whereSql}",
            $params,
            $types
        );

        $sortMap = [
            'name'       => 'c.email',
            'email'      => 'c.email',
            'updated_at' => 'c.updated_at',
        ];
        $sortColumn = $sortMap[$requestedSort] ?? 'c.updated_at';
        $sortFieldUsed = isset($sortMap[$requestedSort]) ? $requestedSort : 'updated_at';

        $sql = "SELECT c.*
                FROM customers c
                WHERE {$whereSql}
                ORDER BY {$sortColumn} {$requestedDir}, c.id DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        $items = array_map(fn (array $row): array => $this->hydrateCustomerRow($row, $channelIds), $rows);

        return $this->buildListResponse($items, $total, $page, $perPage, $sortFieldUsed, $requestedDir, $permittedAccountsByChannel);
    }

    /**
     * @param array<string, mixed> $authorizedScope
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>|null
     */
    private function profileCustomer(
        CatalogEntityReference $ref,
        array $authorizedScope,
        array $permittedAccountsByChannel,
        array $dateRange
    ): ?array {
        $permittedCustomerChannels = $this->resolvePermittedCustomerChannels($authorizedScope, null);
        if ($permittedCustomerChannels === []) {
            return null;
        }

        $channelIds = [];
        foreach ($permittedCustomerChannels as $chName) {
            $cid = $this->resolveChannelId($chName);
            if ($cid !== null) {
                $channelIds[] = $cid;
            }
        }
        if ($channelIds === []) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT c.*
             FROM customers c
             WHERE c.id = :id
               AND EXISTS (SELECT 1 FROM channeled_customers cc WHERE cc.customer_id = c.id AND cc.channel IN (:channelIds))',
            [
                'id'         => $ref->getId(),
                'channelIds' => $channelIds,
            ],
            [
                'channelIds' => ArrayParameterType::INTEGER,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $hydrated = $this->hydrateCustomerRow($row, $channelIds, $ref->getChannel());
        $customerId = (int)$row['id'];
        $channelName = (string)$hydrated['channel'];

        $ccRows = $this->connection->fetchAllAssociative(
            'SELECT cc.* FROM channeled_customers cc WHERE cc.customer_id = :customerId AND cc.channel IN (:channelIds) ORDER BY cc.id ASC',
            ['customerId' => $customerId, 'channelIds' => $channelIds],
            ['channelIds' => ArrayParameterType::INTEGER]
        );

        $sourceRecords = [
            [
                'layer'       => 'base',
                'table'       => 'customers',
                'id'          => $customerId,
                'platform_id' => (string)$row['email'],
                'channel'     => $channelName,
                'name'        => $hydrated['name'],
            ],
        ];
        foreach ($ccRows as $ccRow) {
            $chSlug = $this->resolveChannelName((int)$ccRow['channel']);
            $sourceRecords[] = [
                'layer'       => 'channeled',
                'table'       => 'channeled_customers',
                'id'          => (int)$ccRow['id'],
                'platform_id' => (string)$ccRow['platform_id'],
                'channel'     => $chSlug,
                'name'        => (string)$row['email'],
            ];
        }

        $permittedAccountIds = $this->flattenPermittedAccountIds($permittedAccountsByChannel);
        $metrics = $this->computeScopedEntityMetrics(
            'customers',
            $customerId,
            $permittedAccountIds,
            null,
            null,
            $dateRange
        );

        return [
            'record'         => $hydrated,
            'source_records' => $sourceRecords,
            'relationships'  => [
                'parents'  => $hydrated['relationships'],
                'children' => [],
            ],
            'metrics'        => $metrics,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>
     */
    private function hydratePageRow(
        array $row,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $preferredChannel = null
    ): array {
        $id = (int)$row['id'];
        $data = $this->decodeJson($row['data'] ?? null);
        $matchedAccount = $this->resolveOwningAccountForPage($row, $permittedAccountsByChannel, $permittedAccountIds, $preferredChannel);
        $channelName = $matchedAccount['channel'] ?? ($preferredChannel ?: 'google_search_console');
        $owningAccountId = (int)($matchedAccount['id'] ?? 0);

        $title = trim((string)($row['title'] ?? ''));
        $url = trim((string)($row['url'] ?? ''));
        $name = $title !== '' ? $title : ($url !== '' ? $url : ('Page #' . $id));

        $relationships = [];
        if ($owningAccountId > 0) {
            $relationships['account'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $owningAccountId),
                'entity_type' => 'accounts',
                'id'          => $owningAccountId,
                'name'        => (string)($matchedAccount['name'] ?? ('Account #' . ($matchedAccount['platform_id'] ?? $owningAccountId))),
                'platform_id' => (string)($matchedAccount['platform_id'] ?? ''),
            ];
        }

        return [
            'reference'         => CatalogEntityReference::format('base', $channelName, 'pages', $id),
            'id'                => $id,
            'owning_account_id' => $owningAccountId > 0 ? $owningAccountId : null,
            'entity_type'       => 'pages',
            'name'              => $name,
            'platform_id'       => (string)($row['platform_id'] ?? $url),
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => 'ACTIVE',
            'attributes'        => [
                'url'          => $url !== '' ? $url : null,
                'title'        => $title !== '' ? $title : null,
                'hostname'     => $row['hostname'] ?? null,
                'canonical_id' => !empty($row['canonical_id']) ? (int)$row['canonical_id'] : null,
                'category'     => $data['category'] ?? null,
                'followers'    => isset($data['followers_count']) ? (int)$data['followers_count'] : (isset($data['fan_count']) ? (int)$data['fan_count'] : null),
            ],
            'provenance'        => $this->buildProvenance('base', 'pages', $row),
            'time_semantics'    => [
                'Page metadata reflects latest crawled or synced snapshot; search and analytics metrics use daily reporting dates.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePostRow(array $row): array
    {
        $id = (int)$row['id'];
        $accountId = !empty($row['channeled_account_id']) ? (int)$row['channeled_account_id'] : 0;
        $pageId = !empty($row['page_id']) ? (int)$row['page_id'] : 0;
        $channelName = 'facebook_organic';
        $data = $this->decodeJson($row['data'] ?? null);

        $title = $this->extractPostTitle($data, (string)$row['post_id']);
        $publishedAt = $data['created_time'] ?? $data['timestamp'] ?? $data['published_at'] ?? null;
        $mediaType = $data['media_type'] ?? $data['type'] ?? $data['status_type'] ?? 'POST';
        $permalink = $data['permalink_url'] ?? $data['permalink'] ?? null;
        $previewUrl = $data['full_picture'] ?? $data['media_url'] ?? $data['thumbnail_url'] ?? null;

        $relationships = [];
        if ($accountId > 0) {
            $relationships['account'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $accountId),
                'entity_type' => 'accounts',
                'id'          => $accountId,
                'name'        => (string)($row['account_name'] ?? ('Account #' . ($row['account_platform_id'] ?? $accountId))),
                'platform_id' => (string)($row['account_platform_id'] ?? ''),
            ];
        }
        if ($pageId > 0) {
            $relationships['page'] = [
                'reference'   => CatalogEntityReference::format('base', $channelName, 'pages', $pageId),
                'entity_type' => 'pages',
                'id'          => $pageId,
                'name'        => (string)($row['page_title'] ?: ($row['page_url'] ?: ('Page #' . $pageId))),
                'platform_id' => (string)($row['page_platform_id'] ?? ''),
            ];
        }

        $provenanceRow = $row;
        if (is_string($publishedAt) && trim($publishedAt) !== '') {
            $provenanceRow['platform_created_at'] = $publishedAt;
        }
        $provenanceRow['platform_id'] = (string)$row['post_id'];

        return [
            'reference'         => CatalogEntityReference::format('channeled', $channelName, 'posts', $id),
            'id'                => $id,
            'owning_account_id' => $accountId > 0 ? $accountId : null,
            'entity_type'       => 'posts',
            'name'              => $title,
            'platform_id'       => (string)$row['post_id'],
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => 'PUBLISHED',
            'attributes'        => [
                'post_id'      => (string)$row['post_id'],
                'media_type'   => strtoupper((string)$mediaType),
                'published_at' => is_string($publishedAt) ? $publishedAt : null,
                'permalink'    => is_string($permalink) ? $permalink : null,
                'preview_url'  => is_string($previewUrl) ? $previewUrl : null,
                'caption'      => $data['message'] ?? $data['caption'] ?? null,
            ],
            'provenance'        => $this->buildProvenance('channeled', 'posts', $provenanceRow),
            'time_semantics'    => [
                'Post publication timestamp reflects source platform creation; engagement metrics include daily and lifetime snapshots.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>
     */
    private function hydrateQueryRow(
        array $row,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $searchType = null,
        ?string $preferredChannel = null
    ): array {
        $id = (int)$row['id'];
        $queryText = (string)$row['query'];
        $matchedAccount = $this->resolveOwningAccountForQuery($id, $permittedAccountsByChannel, $permittedAccountIds, $preferredChannel);
        $channelName = $matchedAccount['channel'] ?? ($preferredChannel ?: 'google_search_console');
        $owningAccountId = (int)($matchedAccount['id'] ?? 0);

        $relationships = [];
        if ($owningAccountId > 0) {
            $relationships['account'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $owningAccountId),
                'entity_type' => 'accounts',
                'id'          => $owningAccountId,
                'name'        => (string)($matchedAccount['name'] ?? ('Property #' . ($matchedAccount['platform_id'] ?? $owningAccountId))),
                'platform_id' => (string)($matchedAccount['platform_id'] ?? ''),
            ];
        }

        $provenanceRow = $row;
        $provenanceRow['platform_id'] = $queryText;

        return [
            'reference'         => CatalogEntityReference::format('base', $channelName, 'queries', $id),
            'id'                => $id,
            'owning_account_id' => $owningAccountId > 0 ? $owningAccountId : null,
            'entity_type'       => 'queries',
            'name'              => $queryText,
            'platform_id'       => $queryText,
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => 'ACTIVE',
            'attributes'        => [
                'query'       => $queryText,
                'search_type' => $searchType ?? 'web',
            ],
            'provenance'        => $this->buildProvenance('base', 'queries', $provenanceRow),
            'time_semantics'    => [
                'Search query metrics are aggregated by daily reporting date; average position is weighted by impressions when available.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>
     */
    private function hydrateEventRow(
        array $row,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $preferredChannel = null
    ): array {
        $id = (int)$row['id'];
        $name = (string)$row['name'];
        $matchedAccount = $this->resolveOwningAccountForEvent($id, $permittedAccountsByChannel, $permittedAccountIds, $preferredChannel);
        $channelName = $matchedAccount['channel'] ?? ($preferredChannel ?: 'google_analytics');
        $owningAccountId = (int)($matchedAccount['id'] ?? 0);

        $occurrenceCount = (int)$this->connection->fetchOne(
            'SELECT COUNT(ce.id) FROM channeled_events ce WHERE ce.event_id = :eventId AND ce.channeled_account_id IN (:accountIds)',
            ['eventId' => $id, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );

        $relationships = [];
        if ($owningAccountId > 0) {
            $relationships['account'] = [
                'reference'   => CatalogEntityReference::format('channeled', $channelName, 'accounts', $owningAccountId),
                'entity_type' => 'accounts',
                'id'          => $owningAccountId,
                'name'        => (string)($matchedAccount['name'] ?? ('Account #' . ($matchedAccount['platform_id'] ?? $owningAccountId))),
                'platform_id' => (string)($matchedAccount['platform_id'] ?? ''),
            ];
        }

        $provenanceRow = $row;
        $provenanceRow['platform_id'] = $name;

        return [
            'reference'         => CatalogEntityReference::format('base', $channelName, 'events', $id),
            'id'                => $id,
            'owning_account_id' => $owningAccountId > 0 ? $owningAccountId : null,
            'entity_type'       => 'events',
            'name'              => $name,
            'platform_id'       => $name,
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => 'ACTIVE',
            'attributes'        => [
                'event_name'       => $name,
                'occurrence_count' => $occurrenceCount,
            ],
            'provenance'        => $this->buildProvenance('base', 'events', $provenanceRow),
            'time_semantics'    => [
                'Analytics event counts use daily reporting grain; webhook or recipient occurrences reflect source event timestamps.',
            ],
            'relationships'     => $relationships,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, int> $permittedChannelIds
     * @return array<string, mixed>
     */
    private function hydrateCustomerRow(
        array $row,
        array $permittedChannelIds,
        ?string $preferredChannel = null
    ): array {
        $id = (int)$row['id'];
        $email = (string)($row['email'] ?? '');

        $ccRows = $this->connection->fetchAllAssociative(
            'SELECT cc.* FROM channeled_customers cc WHERE cc.customer_id = :customerId AND cc.channel IN (:channelIds) ORDER BY cc.updated_at DESC, cc.id ASC',
            ['customerId' => $id, 'channelIds' => $permittedChannelIds],
            ['channelIds' => ArrayParameterType::INTEGER]
        );

        $primaryCc = $ccRows[0] ?? null;
        if ($preferredChannel !== null) {
            foreach ($ccRows as $candidate) {
                if ($this->resolveChannelName((int)$candidate['channel']) === $preferredChannel) {
                    $primaryCc = $candidate;
                    break;
                }
            }
        }

        $channelName = $primaryCc !== null
            ? $this->resolveChannelName((int)$primaryCc['channel'])
            : ($preferredChannel ?: 'mailchimp');

        $ccData = $primaryCc !== null ? $this->decodeJson($primaryCc['data'] ?? null) : [];
        $leadData = $primaryCc !== null ? $this->decodeJson($primaryCc['lead_data'] ?? null) : [];

        $fullName = trim((string)($ccData['full_name'] ?? $ccData['name'] ?? ''));
        if ($fullName === '') {
            $first = trim((string)($ccData['first_name'] ?? $ccData['merge_fields']['FNAME'] ?? ''));
            $last = trim((string)($ccData['last_name'] ?? $ccData['merge_fields']['LNAME'] ?? ''));
            $fullName = trim($first . ' ' . $last);
        }

        $status = strtoupper((string)($ccData['status'] ?? ($leadData !== [] ? 'LEAD' : 'SUBSCRIBED')));
        $platformId = (string)($primaryCc['platform_id'] ?? $email);

        $provenanceRow = $row;
        $provenanceRow['platform_id'] = $platformId;
        $provenanceRow['platform_created_at'] = $primaryCc['platform_created_at'] ?? null;

        return [
            'reference'         => CatalogEntityReference::format('base', $channelName, 'customers', $id),
            'id'                => $id,
            'owning_account_id' => null,
            'entity_type'       => 'customers',
            'name'              => $fullName !== '' ? sprintf('%s (%s)', $fullName, $email) : $email,
            'platform_id'       => $platformId,
            'channel'           => $channelName,
            'channel_label'     => self::SUPPORTED_CHANNELS[$channelName] ?? $channelName,
            'status'            => $status,
            'attributes'        => [
                'email'            => $email,
                'full_name'        => $fullName !== '' ? $fullName : null,
                'subscription'     => $ccData['status'] ?? null,
                'vip'              => isset($ccData['vip']) ? (bool)$ccData['vip'] : null,
                'lead_form_id'     => $leadData['form_id'] ?? null,
                'lead_campaign_id' => $leadData['campaign_id'] ?? null,
                'source_count'     => count($ccRows),
            ],
            'provenance'        => $this->buildProvenance('base', 'customers', $provenanceRow),
            'time_semantics'    => [
                'Customer profile reflects latest synced CRM or lead submission state.',
            ],
            'relationships'     => [],
        ];
    }

    /**
     * @param array<string, mixed> $authorizedScope
     * @return array<int, string>
     */
    private function resolvePermittedCustomerChannels(array $authorizedScope, ?string $channelFilter): array
    {
        $channelsScope = $authorizedScope['channels'] ?? null;
        if (!is_array($channelsScope) || $channelsScope === []) {
            return [];
        }

        $permitted = [];
        foreach ($channelsScope as $slug => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $hasAssets = !empty($entry['account_ids']) || !empty($entry['platform_ids']);
            if (!$hasAssets) {
                continue;
            }
            $normalized = strtolower(trim((string)$slug));
            if ($normalized === 'mailchimp') {
                $permitted['mailchimp'] = 'mailchimp';
            } elseif (in_array($normalized, ['facebook_leads', 'facebook_organic', 'facebook_marketing'], true)) {
                $permitted['facebook_leads'] = 'facebook_leads';
            }
        }

        if ($channelFilter !== null) {
            $filterNorm = strtolower(trim($channelFilter));
            if (in_array($filterNorm, ['facebook_organic', 'facebook_marketing'], true)) {
                $filterNorm = 'facebook_leads';
            }
            if (!isset($permitted[$filterNorm])) {
                return [];
            }

            return [$filterNorm];
        }

        return array_values($permitted);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<string, mixed>|null
     */
    private function findPermittedAccountRow(array $permittedAccountsByChannel, int $accountId): ?array
    {
        foreach ($permittedAccountsByChannel as $ch => $accounts) {
            foreach ($accounts as $acc) {
                if ((int)($acc['id'] ?? 0) === $accountId) {
                    $acc['channel'] = $ch;

                    return $acc;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pageRow
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>|null
     */
    private function resolveOwningAccountForPage(
        array $pageRow,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $preferredChannel = null
    ): ?array {
        $pageId = (int)$pageRow['id'];
        $platformId = trim((string)($pageRow['platform_id'] ?? ''));
        $baseAccountId = !empty($pageRow['account_id']) ? (int)$pageRow['account_id'] : 0;

        foreach ($permittedAccountsByChannel as $ch => $accounts) {
            if ($preferredChannel !== null && $ch !== $preferredChannel) {
                continue;
            }
            foreach ($accounts as $acc) {
                if ($platformId !== '' && (string)($acc['platform_id'] ?? '') === $platformId) {
                    $acc['channel'] = $ch;

                    return $acc;
                }
                if ($baseAccountId > 0 && (int)($acc['account_id'] ?? 0) === $baseAccountId) {
                    $acc['channel'] = $ch;

                    return $acc;
                }
            }
        }

        if ($permittedAccountIds !== []) {
            $mcAccId = $this->connection->fetchOne(
                'SELECT mc.channeled_account_id
                 FROM metric_configs mc
                 WHERE mc.page_id = :pageId AND mc.channeled_account_id IN (:accountIds)
                 LIMIT 1',
                ['pageId' => $pageId, 'accountIds' => $permittedAccountIds],
                ['accountIds' => ArrayParameterType::INTEGER]
            );
            if ($mcAccId !== false && $mcAccId !== null) {
                return $this->findPermittedAccountRow($permittedAccountsByChannel, (int)$mcAccId);
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>|null
     */
    private function resolveOwningAccountForQuery(
        int $queryId,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $preferredChannel = null
    ): ?array {
        if ($permittedAccountIds === []) {
            return null;
        }

        $mcAccId = $this->connection->fetchOne(
            'SELECT mc.channeled_account_id
             FROM metric_configs mc
             WHERE mc.query_id = :queryId AND mc.channeled_account_id IN (:accountIds)
             LIMIT 1',
            ['queryId' => $queryId, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );

        if ($mcAccId !== false && $mcAccId !== null) {
            return $this->findPermittedAccountRow($permittedAccountsByChannel, (int)$mcAccId);
        }

        return null;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @param array<int, int> $permittedAccountIds
     * @return array<string, mixed>|null
     */
    private function resolveOwningAccountForEvent(
        int $eventId,
        array $permittedAccountsByChannel,
        array $permittedAccountIds,
        ?string $preferredChannel = null
    ): ?array {
        if ($permittedAccountIds === []) {
            return null;
        }

        $mcAccId = $this->connection->fetchOne(
            'SELECT mc.channeled_account_id
             FROM metric_configs mc
             WHERE mc.event_id = :eventId AND mc.channeled_account_id IN (:accountIds)
             LIMIT 1',
            ['eventId' => $eventId, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );
        if ($mcAccId !== false && $mcAccId !== null) {
            return $this->findPermittedAccountRow($permittedAccountsByChannel, (int)$mcAccId);
        }

        $ceAccId = $this->connection->fetchOne(
            'SELECT ce.channeled_account_id
             FROM channeled_events ce
             WHERE ce.event_id = :eventId AND ce.channeled_account_id IN (:accountIds)
             LIMIT 1',
            ['eventId' => $eventId, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );
        if ($ceAccId !== false && $ceAccId !== null) {
            return $this->findPermittedAccountRow($permittedAccountsByChannel, (int)$ceAccId);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function extractPostTitle(array $postData, string $fallbackId): string
    {
        $candidates = [
            $postData['message'] ?? null,
            $postData['caption'] ?? null,
            $postData['title'] ?? null,
            $postData['story'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $clean = preg_replace('/\s+/', ' ', trim($candidate)) ?? trim($candidate);

                return mb_strlen($clean) > 80 ? mb_substr($clean, 0, 77) . '...' : $clean;
            }
        }

        return 'Post #' . $fallbackId;
    }

    /**
     * @param array<int, int> $scopedAccountIds
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>
     */
    private function computeScopedEntityMetrics(
        string $entityType,
        int $entityId,
        array $scopedAccountIds,
        ?int $channelId,
        ?string $currency,
        array $dateRange,
        bool $allowLifetime = false,
        ?string $searchType = null
    ): array {
        if ($scopedAccountIds === []) {
            return $this->emptyMetricsEnvelope($dateRange, $currency, $entityType);
        }

        $where = ['mc.channeled_account_id IN (:accountIds)'];
        $params = [
            'accountIds' => $scopedAccountIds,
            'startDate'  => $dateRange['start_date'],
            'endDate'    => $dateRange['end_date'],
        ];
        $types = ['accountIds' => ArrayParameterType::INTEGER];

        if ($channelId !== null) {
            $where[] = 'mc.channel = :channelId';
            $params['channelId'] = $channelId;
        }

        if ($entityType === 'pages') {
            $where[] = 'mc.page_id = :entityId';
            $where[] = 'mc.query_id IS NULL';
            $where[] = 'mc.post_id IS NULL';
        } elseif ($entityType === 'posts') {
            $where[] = 'mc.post_id = :entityId';
        } elseif ($entityType === 'queries') {
            $where[] = 'mc.query_id = :entityId';
            $where[] = 'mc.page_id IS NULL';
        } elseif ($entityType === 'events') {
            $where[] = 'mc.event_id = :entityId';
        } elseif ($entityType === 'customers') {
            $where[] = 'mc.customer_id = :entityId';
        }
        $params['entityId'] = $entityId;

        if ($allowLifetime) {
            $where[] = "LOWER(mc.period) IN ('daily', 'lifetime')";
        } else {
            $where[] = "LOWER(mc.period) = 'daily'";
        }

        if ($entityType === 'queries') {
            if ($searchType !== null && $searchType !== '') {
                $params['searchType'] = strtolower($searchType);
                if (strtolower($searchType) === 'web') {
                    $where[] = "(mc.dimension_set_id IS NULL OR EXISTS (
                        SELECT 1 FROM dimension_set_items dsi
                        INNER JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                        INNER JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                        WHERE dsi.dimension_set_id = mc.dimension_set_id
                          AND LOWER(dk.name) = 'search_type'
                          AND LOWER(dv.value) = :searchType
                    ))";
                } else {
                    $where[] = "EXISTS (
                        SELECT 1 FROM dimension_set_items dsi
                        INNER JOIN dimension_values dv ON dv.id = dsi.dimension_value_id
                        INNER JOIN dimension_keys dk ON dk.id = dv.dimension_key_id
                        WHERE dsi.dimension_set_id = mc.dimension_set_id
                          AND LOWER(dk.name) = 'search_type'
                          AND LOWER(dv.value) = :searchType
                    )";
                }
            }
        } else {
            $where[] = 'mc.dimension_set_id IS NULL';
        }

        $where[] = 'mc.country_id IS NULL';
        $where[] = 'mc.device_id IS NULL';
        $where[] = 'm.metric_date >= :startDate';
        $where[] = 'm.metric_date <= :endDate';

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT m.metric_date, LOWER(mc.name) AS metric_name, SUM(m.value) AS total_value
                FROM metrics m
                INNER JOIN metric_configs mc ON mc.id = m.metric_config_id
                WHERE {$whereSql}
                GROUP BY m.metric_date, LOWER(mc.name)
                ORDER BY m.metric_date ASC";

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return $this->aggregateMetricRowsIntoEnvelope($rows, $dateRange, $currency, $entityType);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>
     */
    private function aggregateMetricRowsIntoEnvelope(
        array $rows,
        array $dateRange,
        ?string $currency,
        string $grainUsed
    ): array {
        $dailyBuckets = [];
        $totals = $this->newMetricAccumulator();

        foreach ($rows as $row) {
            $date = substr((string)$row['metric_date'], 0, 10);
            $metricName = strtolower(trim((string)$row['metric_name']));
            $val = (float)($row['total_value'] ?? 0.0);

            if (!isset($dailyBuckets[$date])) {
                $dailyBuckets[$date] = $this->newMetricAccumulator();
            }

            foreach (self::METRIC_NAME_MAP as $canonicalKey => $rawNames) {
                if (in_array($metricName, $rawNames, true)) {
                    if ($canonicalKey === 'roas_purchase_ratio') {
                        $dailyBuckets[$date]['roas_ratio'] = $val;
                        $totals['roas_ratio_sum'] += $val;
                        $totals['roas_ratio_obs_days']++;
                    } elseif ($canonicalKey === 'position_raw') {
                        $dailyBuckets[$date]['position_sum'] += $val;
                        $dailyBuckets[$date]['position_obs_days']++;
                    } elseif ($canonicalKey === 'open_rate_raw') {
                        $dailyBuckets[$date]['open_rate_raw'] = $val;
                        $totals['open_rate_sum'] += $val;
                        $totals['open_rate_obs_days']++;
                    } elseif ($canonicalKey === 'click_rate_raw') {
                        $dailyBuckets[$date]['click_rate_raw'] = $val;
                        $totals['click_rate_sum'] += $val;
                        $totals['click_rate_obs_days']++;
                    } else {
                        $dailyBuckets[$date][$canonicalKey] += $val;
                        $totals[$canonicalKey] += $val;
                    }
                    break;
                }
            }
        }

        // Accumulate weighted position across daily buckets
        foreach ($dailyBuckets as $bucket) {
            if ($bucket['position_obs_days'] > 0) {
                $dayPos = $bucket['position_sum'] / $bucket['position_obs_days'];
                $totals['position_sum'] += $dayPos;
                $totals['position_obs_days']++;
                if ($bucket['impressions'] > 0) {
                    $totals['position_weighted_sum'] += $dayPos * $bucket['impressions'];
                    $totals['position_weight_impr'] += $bucket['impressions'];
                }
            }
        }

        $series = [];
        foreach ($dailyBuckets as $date => $bucket) {
            $series[$date] = $this->attachDerivedMetrics($bucket);
        }

        $observedDays = count($dailyBuckets);
        $hasData = $observedDays > 0;
        $dates = array_keys($dailyBuckets);
        sort($dates);

        $firstMetricDate = $hasData ? $dates[0] : null;
        $lastMetricDate = $hasData ? $dates[count($dates) - 1] : null;

        $coverageState = 'no_data';
        if ($hasData) {
            $coverageState = $observedDays >= $dateRange['requested_days'] ? 'complete' : 'partial';
        }

        $summary = $hasData ? $this->attachDerivedMetrics($totals) : $this->nullMetricSummary();

        return [
            'date_range' => [
                'start_date' => $dateRange['start_date'],
                'end_date'   => $dateRange['end_date'],
                'grain'      => 'daily',
            ],
            'currency'   => $currency,
            'grain_used' => $grainUsed,
            'coverage'   => [
                'state'             => $coverageState,
                'has_data'          => $hasData,
                'observed_days'     => $observedDays,
                'requested_days'    => $dateRange['requested_days'],
                'first_metric_date' => $firstMetricDate,
                'last_metric_date'  => $lastMetricDate,
            ],
            'summary'    => $summary,
            'series'     => $series,
        ];
    }

    /**
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>
     */
    private function emptyMetricsEnvelope(array $dateRange, ?string $currency, string $grainUsed): array
    {
        return $this->aggregateMetricRowsIntoEnvelope([], $dateRange, $currency, $grainUsed);
    }

    /**
     * @return array<string, float|int|null>
     */
    private function newMetricAccumulator(): array
    {
        return [
            'spend'                 => 0.0,
            'impressions'           => 0.0,
            'clicks'                => 0.0,
            'reach'                 => 0.0,
            'conversions'           => 0.0,
            'purchases'             => 0.0,
            'purchase_value'        => 0.0,
            'leads'                 => 0.0,
            'views'                 => 0.0,
            'sessions'              => 0.0,
            'total_users'           => 0.0,
            'event_count'           => 0.0,
            'likes'                 => 0.0,
            'comments'              => 0.0,
            'shares'                => 0.0,
            'saves'                 => 0.0,
            'total_interactions'    => 0.0,
            'sends'                 => 0.0,
            'opens'                 => 0.0,
            'bounces'               => 0.0,
            'unsubscribes'          => 0.0,
            'roas_ratio'            => null,
            'roas_ratio_sum'        => 0.0,
            'roas_ratio_obs_days'   => 0,
            'position_sum'          => 0.0,
            'position_obs_days'     => 0,
            'position_weighted_sum' => 0.0,
            'position_weight_impr'  => 0.0,
            'open_rate_raw'         => null,
            'open_rate_sum'         => 0.0,
            'open_rate_obs_days'    => 0,
            'click_rate_raw'        => null,
            'click_rate_sum'        => 0.0,
            'click_rate_obs_days'   => 0,
        ];
    }

    /**
     * @return array<string, null>
     */
    private function nullMetricSummary(): array
    {
        return [
            'spend'               => null,
            'impressions'         => null,
            'clicks'              => null,
            'reach'               => null,
            'conversions'         => null,
            'purchases'           => null,
            'purchase_value'      => null,
            'leads'               => null,
            'views'               => null,
            'sessions'            => null,
            'total_users'         => null,
            'event_count'         => null,
            'likes'               => null,
            'comments'            => null,
            'shares'              => null,
            'saves'               => null,
            'total_interactions'  => null,
            'sends'               => null,
            'opens'               => null,
            'bounces'             => null,
            'unsubscribes'        => null,
            'open_rate'           => null,
            'click_rate'          => null,
            'position'            => null,
            'ctr'                 => null,
            'cpc'                 => null,
            'cpm'                 => null,
            'cost_per_conversion' => null,
            'roas_purchase'       => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMailchimpCampaignEventMilestones(
        int $campaignId,
        string $campaignPlatformId,
        int $owningAccountId,
        bool $canViewPii
    ): array {
        if ($owningAccountId <= 0) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ce.id, ce.platform_id, ce.data, ce.created_at
             FROM channeled_events ce
             WHERE ce.channeled_account_id = :accountId
             ORDER BY ce.id DESC
             LIMIT 250',
            ['accountId' => $owningAccountId]
        );

        $milestones = [];
        foreach ($rows as $row) {
            $data = $this->decodeJson($row['data'] ?? null);
            $eventCampId = (string)($data['campaign_id'] ?? $data['channeled_campaign_id'] ?? '');
            if ($eventCampId !== $campaignPlatformId && $eventCampId !== (string)$campaignId) {
                continue;
            }

            $action = strtolower(trim((string)($data['action'] ?? $data['event_name'] ?? 'activity')));
            $timestamp = (string)($data['timestamp'] ?? $row['created_at'] ?? '');
            if ($timestamp === '') {
                continue;
            }

            $emailHash = (string)($data['email_id'] ?? $data['email_hash'] ?? '');
            $maskedRecipient = $emailHash !== '' ? ('recipient:' . substr($emailHash, 0, 8)) : 'recipient:redacted';
            $recipientLabel = $maskedRecipient;
            if ($canViewPii && !empty($data['email_address']) && is_string($data['email_address'])) {
                $recipientLabel = $data['email_address'];
            }

            $milestones[] = [
                'id'            => 'mc_event:' . $row['id'],
                'kind'          => 'recipient_' . $action,
                'label'         => 'Recipient ' . ucfirst($action),
                'description'   => sprintf('Recipient (%s) recorded %s action on campaign.', $recipientLabel, $action),
                'occurred_at'   => $this->normalizeIsoTimestamp($timestamp),
                'quality'       => 'source_timestamp',
                'quality_label' => 'Source timestamp',
                'channel'       => 'mailchimp',
                'metadata'      => array_filter([
                    'action'      => $action,
                    'url'         => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
                    'bounce_type' => isset($data['bounce_type']) && is_string($data['bounce_type']) ? $data['bounce_type'] : (isset($data['type']) && is_string($data['type']) ? $data['type'] : null),
                    'is_proxy'    => isset($data['is_proxy']) ? (bool)$data['is_proxy'] : null,
                    'recipient'   => $recipientLabel,
                ], static fn ($v): bool => $v !== null),
            ];
        }

        return $milestones;
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchEventOccurrenceMilestones(
        int $eventId,
        string $eventName,
        array $permittedAccountIds,
        bool $canViewPii
    ): array {
        if ($permittedAccountIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ce.id, ce.platform_id, ce.channel, ce.data, ce.created_at
             FROM channeled_events ce
             WHERE ce.event_id = :eventId AND ce.channeled_account_id IN (:accountIds)
             ORDER BY ce.id DESC
             LIMIT 100',
            ['eventId' => $eventId, 'accountIds' => $permittedAccountIds],
            ['accountIds' => ArrayParameterType::INTEGER]
        );

        $milestones = [];
        foreach ($rows as $row) {
            $data = $this->decodeJson($row['data'] ?? null);
            $chName = $this->resolveChannelName((int)$row['channel']);
            $timestamp = (string)($data['timestamp'] ?? $row['created_at'] ?? '');
            if ($timestamp === '') {
                continue;
            }

            $action = (string)($data['action'] ?? $eventName);
            $emailHash = (string)($data['email_id'] ?? $data['email_hash'] ?? '');
            $recipient = $emailHash !== '' ? ('recipient:' . substr($emailHash, 0, 8)) : null;
            if ($canViewPii && !empty($data['email_address']) && is_string($data['email_address'])) {
                $recipient = $data['email_address'];
            }

            $milestones[] = [
                'id'            => 'channeled_event:' . $row['id'],
                'kind'          => 'event_occurrence',
                'label'         => sprintf('Event occurrence: %s', $eventName),
                'description'   => sprintf('Recorded %s occurrence (%s).', $action, $row['platform_id']),
                'occurred_at'   => $this->normalizeIsoTimestamp($timestamp),
                'quality'       => 'source_timestamp',
                'quality_label' => 'Source timestamp',
                'channel'       => $chName,
                'metadata'      => array_filter([
                    'platform_id' => (string)$row['platform_id'],
                    'action'      => $action,
                    'campaign_id' => isset($data['campaign_id']) ? (string)$data['campaign_id'] : null,
                    'url'         => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
                    'bounce_type' => isset($data['bounce_type']) && is_string($data['bounce_type']) ? $data['bounce_type'] : null,
                    'recipient'   => $recipient,
                ], static fn ($v): bool => $v !== null),
            ];
        }

        return $milestones;
    }

    /**
     * @param array<string, mixed> $authorizedScope
     * @return array<int, array<string, mixed>>
     */
    private function fetchCustomerActivityMilestones(int $customerId, array $authorizedScope): array
    {
        $permittedCustomerChannels = $this->resolvePermittedCustomerChannels($authorizedScope, null);
        if ($permittedCustomerChannels === []) {
            return [];
        }

        $channelIds = [];
        foreach ($permittedCustomerChannels as $chName) {
            $cid = $this->resolveChannelId($chName);
            if ($cid !== null) {
                $channelIds[] = $cid;
            }
        }
        if ($channelIds === []) {
            return [];
        }

        $ccRows = $this->connection->fetchAllAssociative(
            'SELECT cc.* FROM channeled_customers cc WHERE cc.customer_id = :customerId AND cc.channel IN (:channelIds) ORDER BY cc.id ASC',
            ['customerId' => $customerId, 'channelIds' => $channelIds],
            ['channelIds' => ArrayParameterType::INTEGER]
        );

        $milestones = [];
        foreach ($ccRows as $ccRow) {
            $chName = $this->resolveChannelName((int)$ccRow['channel']);
            $chLabel = self::SUPPORTED_CHANNELS[$chName] ?? $chName;
            $leadData = $this->decodeJson($ccRow['lead_data'] ?? null);

            if (!empty($ccRow['platform_created_at'])) {
                $milestones[] = [
                    'id'            => 'customer_source_created:' . $ccRow['id'],
                    'kind'          => 'source_profile_created',
                    'label'         => sprintf('Profile registered on %s', $chLabel),
                    'description'   => sprintf('Source customer record (%s) created in %s.', $ccRow['platform_id'], $chLabel),
                    'occurred_at'   => $this->normalizeIsoTimestamp((string)$ccRow['platform_created_at']),
                    'quality'       => 'source_timestamp',
                    'quality_label' => 'Source timestamp',
                    'channel'       => $chName,
                    'metadata'      => [
                        'platform_id' => (string)$ccRow['platform_id'],
                    ],
                ];
            }

            if ($leadData !== []) {
                $leadCreated = (string)($leadData['created_time'] ?? $ccRow['platform_created_at'] ?? $ccRow['created_at'] ?? '');
                if ($leadCreated !== '') {
                    $milestones[] = [
                        'id'            => 'lead_submitted:' . $ccRow['id'],
                        'kind'          => 'lead_submitted',
                        'label'         => 'Lead form submitted',
                        'description'   => sprintf('Lead captured via %s (Form: %s).', $chLabel, $leadData['form_id'] ?? 'N/A'),
                        'occurred_at'   => $this->normalizeIsoTimestamp($leadCreated),
                        'quality'       => 'source_timestamp',
                        'quality_label' => 'Source timestamp',
                        'channel'       => $chName,
                        'metadata'      => array_filter([
                            'form_id'     => $leadData['form_id'] ?? null,
                            'campaign_id' => $leadData['campaign_id'] ?? null,
                            'ad_id'       => $leadData['ad_id'] ?? null,
                            'page_id'     => $leadData['page_id'] ?? null,
                        ], static fn ($v): bool => $v !== null),
                    ];
                }
            }
        }

        return $milestones;
    }

    /**
     * Computes entity-grain metrics over a date window without double counting breakdown passes.
     *
     * @param array{start_date: string, end_date: string, requested_days: int} $dateRange
     * @return array<string, mixed>
     */
    private function computeEntityMetrics(
        string $entityType,
        int $entityId,
        int $owningAccountId,
        int $channelId,
        ?string $currency,
        array $dateRange
    ): array {
        $grainClause = $this->resolveMetricGrainClause($entityType, $entityId, $owningAccountId, $channelId);

        $sql = "SELECT m.metric_date, LOWER(mc.name) AS metric_name, SUM(m.value) AS total_value
                FROM metrics m
                INNER JOIN metric_configs mc ON mc.id = m.metric_config_id
                WHERE {$grainClause['sql']}
                  AND LOWER(mc.period) = 'daily'
                  AND mc.dimension_set_id IS NULL
                  AND mc.country_id IS NULL
                  AND mc.device_id IS NULL
                  AND m.metric_date >= :startDate
                  AND m.metric_date <= :endDate
                GROUP BY m.metric_date, LOWER(mc.name)
                ORDER BY m.metric_date ASC";

        $params = array_merge($grainClause['params'], [
            'startDate' => $dateRange['start_date'],
            'endDate'   => $dateRange['end_date'],
        ]);

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return $this->aggregateMetricRowsIntoEnvelope($rows, $dateRange, $currency, $grainClause['grain_used']);
    }

    /**
     * @param array<string, float|int|null> $bucket
     * @return array<string, float|null>
     */
    private function attachDerivedMetrics(array $bucket): array
    {
        $spend = (float)($bucket['spend'] ?? 0.0);
        $impressions = (float)($bucket['impressions'] ?? 0.0);
        $clicks = (float)($bucket['clicks'] ?? 0.0);
        $reach = (float)($bucket['reach'] ?? 0.0);
        $conversions = (float)($bucket['conversions'] ?? 0.0);
        $purchases = (float)($bucket['purchases'] ?? 0.0);
        $purchaseValue = (float)($bucket['purchase_value'] ?? 0.0);
        $leads = (float)($bucket['leads'] ?? 0.0);
        $views = (float)($bucket['views'] ?? 0.0);
        $sessions = (float)($bucket['sessions'] ?? 0.0);
        $totalUsers = (float)($bucket['total_users'] ?? 0.0);
        $eventCount = (float)($bucket['event_count'] ?? 0.0);
        $likes = (float)($bucket['likes'] ?? 0.0);
        $comments = (float)($bucket['comments'] ?? 0.0);
        $shares = (float)($bucket['shares'] ?? 0.0);
        $saves = (float)($bucket['saves'] ?? 0.0);
        $totalInteractions = (float)($bucket['total_interactions'] ?? 0.0);
        if ($totalInteractions <= 0.0 && ($likes + $comments + $shares + $saves) > 0.0) {
            $totalInteractions = $likes + $comments + $shares + $saves;
        }
        $sends = (float)($bucket['sends'] ?? 0.0);
        $opens = (float)($bucket['opens'] ?? 0.0);
        $bounces = (float)($bucket['bounces'] ?? 0.0);
        $unsubscribes = (float)($bucket['unsubscribes'] ?? 0.0);

        $ctr = $impressions > 0 ? round($clicks / $impressions, 6) : null;
        $cpc = $clicks > 0 ? round($spend / $clicks, 4) : null;
        $cpm = $impressions > 0 ? round(($spend / $impressions) * 1000, 4) : null;
        $costPerConversion = $conversions > 0 ? round($spend / $conversions, 4) : null;

        $roasPurchase = null;
        if ($spend > 0 && $purchaseValue > 0) {
            $roasPurchase = round($purchaseValue / $spend, 4);
        } elseif (isset($bucket['roas_ratio']) && $bucket['roas_ratio'] !== null) {
            $roasPurchase = round((float)$bucket['roas_ratio'], 4);
        } elseif (!empty($bucket['roas_ratio_obs_days']) && (int)$bucket['roas_ratio_obs_days'] > 0) {
            $roasPurchase = round(((float)$bucket['roas_ratio_sum']) / (int)$bucket['roas_ratio_obs_days'], 4);
        }

        $position = null;
        if (!empty($bucket['position_weight_impr']) && (float)$bucket['position_weight_impr'] > 0) {
            $position = round(((float)$bucket['position_weighted_sum']) / (float)$bucket['position_weight_impr'], 2);
        } elseif (!empty($bucket['position_obs_days']) && (int)$bucket['position_obs_days'] > 0) {
            $position = round(((float)$bucket['position_sum']) / (int)$bucket['position_obs_days'], 2);
        }

        $openRate = null;
        if ($sends > 0 && $opens > 0) {
            $openRate = round($opens / $sends, 6);
        } elseif (isset($bucket['open_rate_raw']) && $bucket['open_rate_raw'] !== null) {
            $openRate = round((float)$bucket['open_rate_raw'], 6);
        } elseif (!empty($bucket['open_rate_obs_days']) && (int)$bucket['open_rate_obs_days'] > 0) {
            $openRate = round(((float)$bucket['open_rate_sum']) / (int)$bucket['open_rate_obs_days'], 6);
        }

        $clickRate = null;
        if ($sends > 0 && $clicks > 0) {
            $clickRate = round($clicks / $sends, 6);
        } elseif (isset($bucket['click_rate_raw']) && $bucket['click_rate_raw'] !== null) {
            $clickRate = round((float)$bucket['click_rate_raw'], 6);
        } elseif (!empty($bucket['click_rate_obs_days']) && (int)$bucket['click_rate_obs_days'] > 0) {
            $clickRate = round(((float)$bucket['click_rate_sum']) / (int)$bucket['click_rate_obs_days'], 6);
        }

        return [
            'spend'               => round($spend, 4),
            'impressions'         => round($impressions, 2),
            'clicks'              => round($clicks, 2),
            'reach'               => round($reach, 2),
            'conversions'         => round($conversions, 4),
            'purchases'           => round($purchases, 4),
            'purchase_value'      => round($purchaseValue, 4),
            'leads'               => round($leads, 4),
            'views'               => round($views, 2),
            'sessions'            => round($sessions, 2),
            'total_users'         => round($totalUsers, 2),
            'event_count'         => round($eventCount, 2),
            'likes'               => round($likes, 2),
            'comments'            => round($comments, 2),
            'shares'              => round($shares, 2),
            'saves'               => round($saves, 2),
            'total_interactions'  => round($totalInteractions, 2),
            'sends'               => round($sends, 2),
            'opens'               => round($opens, 2),
            'bounces'             => round($bounces, 2),
            'unsubscribes'        => round($unsubscribes, 2),
            'open_rate'           => $openRate,
            'click_rate'          => $clickRate,
            'position'            => $position,
            'ctr'                 => $ctr,
            'cpc'                 => $cpc,
            'cpm'                 => $cpm,
            'cost_per_conversion' => $costPerConversion,
            'roas_purchase'       => $roasPurchase,
        ];
    }

    /**
     * @return array{sql: string, params: array<string, mixed>, grain_used: string}
     */
    private function resolveMetricGrainClause(string $entityType, int $entityId, int $owningAccountId, ?int $channelId): array
    {
        $params = ['accountId' => $owningAccountId];
        $channelSql = '';
        if ($channelId !== null) {
            $channelSql = ' AND mc.channel = :channelId';
            $params['channelId'] = $channelId;
        }

        if ($entityType === 'accounts') {
            $standaloneCount = (int)$this->connection->fetchOne(
                "SELECT COUNT(mc.id)
                 FROM metric_configs mc
                 WHERE mc.channeled_account_id = :accountId
                   {$channelSql}
                   AND mc.channeled_campaign_id IS NULL
                   AND mc.channeled_ad_group_id IS NULL
                   AND mc.channeled_ad_id IS NULL
                   AND mc.dimension_set_id IS NULL
                   AND mc.country_id IS NULL",
                $params
            );

            if ($standaloneCount > 0) {
                return [
                    'sql'        => "mc.channeled_account_id = :accountId{$channelSql} AND mc.channeled_campaign_id IS NULL AND mc.channeled_ad_group_id IS NULL AND mc.channeled_ad_id IS NULL",
                    'params'     => $params,
                    'grain_used' => 'account',
                ];
            }

            // Fallback to campaign-grain rollup if account-level metric_configs are not persisted directly
            return [
                'sql'        => "mc.channeled_account_id = :accountId{$channelSql} AND mc.channeled_campaign_id IS NOT NULL AND mc.channeled_ad_group_id IS NULL AND mc.channeled_ad_id IS NULL",
                'params'     => $params,
                'grain_used' => 'campaign_rollup',
            ];
        }

        if ($entityType === 'campaigns') {
            $params['campaignId'] = $entityId;
            return [
                'sql'        => "mc.channeled_account_id = :accountId{$channelSql} AND mc.channeled_campaign_id = :campaignId AND mc.channeled_ad_group_id IS NULL AND mc.channeled_ad_id IS NULL",
                'params'     => $params,
                'grain_used' => 'campaign',
            ];
        }

        if (in_array($entityType, ['ad-sets', 'ad-groups'], true)) {
            $params['adGroupId'] = $entityId;
            return [
                'sql'        => "mc.channeled_account_id = :accountId{$channelSql} AND mc.channeled_ad_group_id = :adGroupId AND mc.channeled_ad_id IS NULL",
                'params'     => $params,
                'grain_used' => $entityType,
            ];
        }

        $params['adId'] = $entityId;

        return [
            'sql'        => "mc.channeled_account_id = :accountId{$channelSql} AND mc.channeled_ad_id = :adId",
            'params'     => $params,
            'grain_used' => 'ad',
        ];
    }

    /**
     * @param array<int, int> $permittedAccountIds
     * @return array{first_date: ?string, last_date: ?string, observed_days: int}
     */
    private function fetchEntityMetricBounds(
        string $entityType,
        int $entityId,
        int $owningAccountId,
        ?int $channelId,
        array $permittedAccountIds = []
    ): array {
        if (in_array($entityType, ['pages', 'posts', 'queries', 'events', 'customers'], true)) {
            $scopedIds = $owningAccountId > 0 ? [$owningAccountId] : $permittedAccountIds;
            if ($scopedIds === []) {
                return ['first_date' => null, 'last_date' => null, 'observed_days' => 0];
            }
            $fkCol = match ($entityType) {
                'pages'     => 'mc.page_id',
                'posts'     => 'mc.post_id',
                'queries'   => 'mc.query_id',
                'events'    => 'mc.event_id',
                'customers' => 'mc.customer_id',
            };
            $row = $this->connection->fetchAssociative(
                "SELECT MIN(m.metric_date) AS first_date,
                        MAX(m.metric_date) AS last_date,
                        COUNT(DISTINCT m.metric_date) AS observed_days
                 FROM metrics m
                 INNER JOIN metric_configs mc ON mc.id = m.metric_config_id
                 WHERE {$fkCol} = :entityId
                   AND mc.channeled_account_id IN (:accountIds)",
                ['entityId' => $entityId, 'accountIds' => $scopedIds],
                ['accountIds' => ArrayParameterType::INTEGER]
            );
            if (!is_array($row) || empty($row['first_date'])) {
                return ['first_date' => null, 'last_date' => null, 'observed_days' => 0];
            }

            return [
                'first_date'    => substr((string)$row['first_date'], 0, 10),
                'last_date'     => substr((string)$row['last_date'], 0, 10),
                'observed_days' => (int)($row['observed_days'] ?? 0),
            ];
        }

        $grainClause = $this->resolveMetricGrainClause($entityType, $entityId, $owningAccountId, $channelId);

        $row = $this->connection->fetchAssociative(
            "SELECT MIN(m.metric_date) AS first_date,
                    MAX(m.metric_date) AS last_date,
                    COUNT(DISTINCT m.metric_date) AS observed_days
             FROM metrics m
             INNER JOIN metric_configs mc ON mc.id = m.metric_config_id
             WHERE {$grainClause['sql']}
               AND LOWER(mc.period) = 'daily'
               AND mc.dimension_set_id IS NULL
               AND mc.country_id IS NULL
               AND mc.device_id IS NULL",
            $grainClause['params']
        );

        if (!is_array($row) || empty($row['first_date'])) {
            return ['first_date' => null, 'last_date' => null, 'observed_days' => 0];
        }

        return [
            'first_date'    => substr((string)$row['first_date'], 0, 10),
            'last_date'     => substr((string)$row['last_date'], 0, 10),
            'observed_days' => (int)($row['observed_days'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $dateRange
     * @return array{start_date: string, end_date: string, requested_days: int}
     */
    private function resolveDateRange(array $dateRange): array
    {
        $today = new DateTimeImmutable('today');
        $defaultEnd = $today->format('Y-m-d');
        $defaultStart = $today->modify('-29 days')->format('Y-m-d');

        $rawStart = isset($dateRange['start_date']) && is_string($dateRange['start_date']) ? trim($dateRange['start_date']) : '';
        $rawEnd = isset($dateRange['end_date']) && is_string($dateRange['end_date']) ? trim($dateRange['end_date']) : '';

        $startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart) ? $rawStart : $defaultStart;
        $endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd) ? $rawEnd : $defaultEnd;

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        try {
            $d1 = new DateTimeImmutable($startDate);
            $d2 = new DateTimeImmutable($endDate);
            $days = max(1, (int)$d1->diff($d2)->days + 1);
        } catch (Throwable) {
            $days = 30;
        }

        return [
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'requested_days' => $days,
        ];
    }

    private function resolveChannelId(string $channelName): ?int
    {
        $normalized = strtolower(trim($channelName));
        if (isset($this->channelNameToIdCache[$normalized])) {
            return $this->channelNameToIdCache[$normalized];
        }

        try {
            $id = $this->connection->fetchOne('SELECT id FROM channels WHERE LOWER(name) = :name', ['name' => $normalized]);
            if ($id !== false && $id !== null && is_numeric($id)) {
                $intId = (int)$id;
                $this->channelNameToIdCache[$normalized] = $intId;
                $this->channelIdToNameCache[$intId] = $normalized;

                return $intId;
            }
        } catch (Throwable) {
        }

        $channel = Channel::tryFromName($normalized);
        if ($channel instanceof Channel && $channel->getId() !== null) {
            $intId = (int)$channel->getId();
            $this->channelNameToIdCache[$normalized] = $intId;
            $this->channelIdToNameCache[$intId] = $normalized;

            return $intId;
        }

        return null;
    }

    private function resolveChannelName(int $channelId): string
    {
        if (isset($this->channelIdToNameCache[$channelId])) {
            return $this->channelIdToNameCache[$channelId];
        }

        try {
            $name = $this->connection->fetchOne('SELECT name FROM channels WHERE id = :id', ['id' => $channelId]);
            if (is_string($name) && trim($name) !== '') {
                $normalized = strtolower(trim($name));
                $this->channelIdToNameCache[$channelId] = $normalized;
                $this->channelNameToIdCache[$normalized] = $channelId;

                return $normalized;
            }
        } catch (Throwable) {
        }

        $channel = Channel::tryFrom($channelId);
        if ($channel instanceof Channel) {
            $normalized = strtolower(trim($channel->getName()));
            $this->channelIdToNameCache[$channelId] = $normalized;
            $this->channelNameToIdCache[$normalized] = $channelId;

            return $normalized;
        }

        return (string)$channelId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractCurrency(array $data): ?string
    {
        $candidates = [
            $data['currency'] ?? null,
            $data['currency_code'] ?? null,
            $data['currencyCode'] ?? null,
            $data['account_currency'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractTimezone(array $data): ?string
    {
        $candidates = [
            $data['timezone_name'] ?? null,
            $data['time_zone'] ?? null,
            $data['timeZone'] ?? null,
            $data['timezone'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        return substr($str, 0, 10);
    }

    private function normalizeIsoTimestamp(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            return $trimmed . 'T00:00:00Z';
        }
        try {
            return (new DateTimeImmutable($trimmed))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return $trimmed;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildProvenance(string $sourceLayer, string $sourceTable, array $row): array
    {
        return [
            'source_layer'        => $sourceLayer,
            'source_table'        => $sourceTable,
            'platform_id'         => (string)($row['platform_id'] ?? ''),
            'platform_created_at' => $row['platform_created_at'] ?? null,
            'created_at'          => $row['created_at'] ?? null,
            'updated_at'          => $row['updated_at'] ?? null,
            'freshness_timestamp' => $row['updated_at'] ?? $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $targeting
     */
    private function summarizeTargeting(array $targeting): ?string
    {
        if ($targeting === []) {
            return null;
        }

        $parts = [];
        if (isset($targeting['age_min']) || isset($targeting['age_max'])) {
            $parts[] = sprintf('Age %s-%s', $targeting['age_min'] ?? '18', $targeting['age_max'] ?? '65+');
        }
        if (!empty($targeting['geo_locations']['countries']) && is_array($targeting['geo_locations']['countries'])) {
            $parts[] = 'Countries: ' . implode(', ', array_slice($targeting['geo_locations']['countries'], 0, 5));
        }
        if (!empty($targeting['publisher_platforms']) && is_array($targeting['publisher_platforms'])) {
            $parts[] = 'Platforms: ' . implode(', ', $targeting['publisher_platforms']);
        }

        if ($parts !== []) {
            return implode(' | ', $parts);
        }

        $keys = array_slice(array_keys($targeting), 0, 4);

        return 'Configured (' . implode(', ', $keys) . ')';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<string, mixed>
     */
    private function buildListResponse(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $sort,
        string $dir,
        array $permittedAccountsByChannel
    ): array {
        $lastPage = max(1, (int)ceil($total / max(1, $perPage)));
        $channelCounts = [];
        foreach ($permittedAccountsByChannel as $ch => $accounts) {
            $channelCounts[$ch] = count($accounts);
        }

        return [
            'data' => $items,
            'meta' => [
                'total'         => $total,
                'page'          => $page,
                'per_page'      => $perPage,
                'last_page'     => $lastPage,
                'sort'          => $sort,
                'dir'           => $dir,
                'scope_summary' => [
                    'authorized_channels' => array_keys($permittedAccountsByChannel),
                    'account_counts'      => $channelCounts,
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $permittedAccountsByChannel
     * @return array<string, mixed>
     */
    private function emptyListResponse(
        int $page,
        int $perPage,
        string $sort,
        string $dir,
        array $permittedAccountsByChannel
    ): array {
        return $this->buildListResponse([], 0, $page, $perPage, $sort, $dir, $permittedAccountsByChannel);
    }
}
