<?php

namespace Classes;

use Anibalealvarezs\ApiDriverCore\Interfaces\DimensionManagerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Entities\\Analytics\\Channel;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Entities\Analytics\Account;
use Entities\Analytics\Campaign;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Channeled\ChanneledSyncError;
use Entities\Analytics\Country;
use Entities\Analytics\Device;
use Entities\Analytics\Creative;
use Entities\Analytics\Page;
use Entities\Analytics\Post;
use Entities\Analytics\Query;

class ProductionEntityMapper implements SeederInterface
{
    public function __construct(private \Doctrine\ORM\EntityManager $entityManager)
    {
    }

    public function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->entityManager;
    }

    public function getDimensionManager(): DimensionManagerInterface
    {
        return new \Classes\DimensionManager($this->entityManager);
    }

    public function getEntityClass(string $shortName): string
    {
        return match($shortName) {
            'account', 'Account' => Account::class,
            'channeled_account', 'ChanneledAccount' => ChanneledAccount::class,
            'campaign', 'Campaign' => Campaign::class,
            'channeled_campaign', 'ChanneledCampaign' => ChanneledCampaign::class,
            'ad_group', 'AdGroup' => ChanneledAdGroup::class,
            'channeled_ad_group', 'ChanneledAdGroup' => ChanneledAdGroup::class,
            'ad', 'Ad' => ChanneledAd::class,
            'channeled_ad', 'ChanneledAd' => ChanneledAd::class,
            'creative', 'Creative' => Creative::class,
            'page', 'Page', 'FacebookPage' => Page::class,
            'post', 'Post' => Post::class,
            'query', 'Query' => Query::class,
            'country', 'Country' => Country::class,
            'device', 'Device' => Device::class,
            'ChanneledSyncError' => ChanneledSyncError::class,
            'ChanneledPage' => Page::class,
            default => throw new \Exception("Unknown entity type: $shortName")
        };
    }

    public function getEnumClass(string $shortName): string
    {
        return match($shortName) {
            'channel' => Channel::class,
            'account_type' => \Enums\Account::class,
            'country' => \Anibalealvarezs\ApiSkeleton\Enums\Country::class,
            'device' => \Anibalealvarezs\ApiSkeleton\Enums\Device::class,
            'period' => \Anibalealvarezs\ApiSkeleton\Enums\Period::class,
            default => throw new \Exception("Unknown enum type: $shortName")
        };
    }

    public function processMetricsMassive(Collection $metrics): void
    {
        // This method is primarily for the demo seeder. 
        // Production sync uses real-time processing or background jobs.
    }

    public function getDates(int $days = 180): array
    {
        return [];
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
        // Production sync doesn't use the seeder's queue.
    }

    public function getAges(): array
    {
        return [];
    }

    public function getGenders(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function resolveEntity(string $type, array $params): mixed
    {
        // Production sync doesn't use the seeder for entity resolution.
        // It uses the identityMapper callback.
        return null;
    }
}
