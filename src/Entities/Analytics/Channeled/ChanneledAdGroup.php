<?php

namespace Entities\Analytics\Channeled;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Campaign;
use Entities\Analytics\MetricConfig;
use Enums\BillingEvent;
use Enums\CampaignStatus;
use Enums\OptimizationGoal;
use Repositories\Channeled\ChanneledAdGroupRepository;

#[ORM\Entity(repositoryClass: ChanneledAdGroupRepository::class)]
#[ORM\Table(name: 'channeled_ad_groups')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['channel'], name: 'channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['channeledAccount_id'], name: 'channeledAccount_id_idx')]
#[ORM\Index(columns: ['campaign_id'], name: 'campaign_id_idx')]
#[ORM\Index(columns: ['channeledCampaign_id'], name: 'channeledCampaign_id_idx')]
#[ORM\Index(columns: ['channeledAccount_id', 'campaign_id'], name: 'channeledAccount_id_campaign_id_idx')]
#[ORM\Index(columns: ['channeledAccount_id', 'channeledCampaign_id'], name: 'channeledAccount_id_channeledCampaign_id_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'channeledAccount_id', 'channeledCampaign_id'], name: 'platformId_channel_channeledAccount_id_channeledCampaign_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAdGroup extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $startDate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTime $endDate = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: OptimizationGoal::class)]
    protected ?OptimizationGoal $optimizationGoal = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: CampaignStatus::class)]
    protected ?CampaignStatus $status = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: BillingEvent::class)]
    protected ?BillingEvent $billingEvent = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $targeting = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'channeledAdGroups')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    protected ChanneledAccount $channeledAccount;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'channeledAdGroups')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'channeledAdGroups')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\OneToMany(mappedBy: 'channeledAdGroup', targetEntity: ChanneledAd::class)]
    protected Collection $channeledAds;

    #[ORM\OneToMany(mappedBy: 'channeledAdGroup', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledAds = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ChanneledAdGroup
     */
    public function addName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Gets the campaign start date.
     * @return DateTime|null
     */
    public function getStartDate(): ?DateTime
    {
        return $this->startDate;
    }

    /**
     * Sets the campaign start date.
     * @param DateTime|null $startDate
     * @return self
     */
    public function addStartDate(?DateTime $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    /**
     * Gets the campaign end date.
     * @return DateTime|null
     */
    public function getEndDate(): ?DateTime
    {
        return $this->endDate;
    }

    /**
     * Sets the campaign end date.
     * @param DateTime|null $endDate
     * @return self
     */
    public function addEndDate(?DateTime $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    /**
     * Gets the unique campaign identifier.
     * @return OptimizationGoal|null
     */
    public function getOptimizationGoal(): ?OptimizationGoal
    {
        return $this->optimizationGoal;
    }

    /**
     * Sets the unique campaign identifier.
     * @param OptimizationGoal|null $optimizationGoal
     * @return self
     */
    public function addOptimizationGoal(?OptimizationGoal $optimizationGoal): self
    {
        $this->optimizationGoal = $optimizationGoal;
        return $this;
    }

    /**
     * Gets the unique campaign identifier.
     * @return CampaignStatus|null
     */
    public function getStatus(): ?CampaignStatus
    {
        return $this->status;
    }

    /**
     * Sets the unique campaign identifier.
     * @param CampaignStatus|null $status
     * @return self
     */
    public function addStatus(?CampaignStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Gets the unique campaign identifier.
     * @return BillingEvent|null
     */
    public function getBillingEvent(): ?BillingEvent
    {
        return $this->billingEvent;
    }

    /**
     * Sets the unique campaign identifier.
     * @param BillingEvent|null $billingEvent
     * @return self
     */
    public function addBillingEvent(?BillingEvent $billingEvent): self
    {
        $this->billingEvent = $billingEvent;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getTargeting(): ?array
    {
        return $this->targeting;
    }

    /**
     * @param array|null $targeting
     * @return self
     */
    public function addTargeting(?array $targeting): self
    {
        $this->targeting = $targeting;
        return $this;
    }

    /**
     * Gets the associated global campaign.
     * @return ChanneledAccount
     */
    public function getChanneledAccount(): ChanneledAccount
    {
        return $this->channeledAccount;
    }

    /**
     * Sets the associated global campaign.
     * @param ChanneledAccount|null $channeledAccount
     * @return self
     */
    public function addChanneledAccount(?ChanneledAccount $channeledAccount): self
    {
        $this->channeledAccount = $channeledAccount;
        return $this;
    }

    /**
     * @return Campaign|null
     */
    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    /**
     * @param Campaign|null $campaign
     * @return ChanneledAdGroup
     */
    public function addCampaign(?Campaign $campaign): self
    {
        $this->campaign = $campaign;
        return $this;
    }

    /**
     * @return ChanneledCampaign|null
     */
    public function getChanneledCampaign(): ?ChanneledCampaign
    {
        return $this->channeledCampaign;
    }

    /**
     * @param ChanneledCampaign|null $channeledCampaign
     * @return ChanneledAdGroup
     */
    public function addChanneledCampaign(?ChanneledCampaign $channeledCampaign): self
    {
        $this->channeledCampaign = $channeledCampaign;
        return $this;
    }

    /**
     * @return Collection<int, ChanneledAd>
     */
    public function getChanneledAds(): Collection
    {
        return $this->channeledAds;
    }

    /**
     * @param ChanneledAd $channeledAd
     * @return ChanneledAdGroup
     */
    public function addChanneledAd(ChanneledAd $channeledAd): self
    {
        if (!$this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->add($channeledAd);
            $channeledAd->addChanneledAdGroup($this);
        }
        return $this;
    }

    /**
     * @param ChanneledAd $channeledAd
     * @return ChanneledAdGroup
     */
    public function removeChanneledAd(ChanneledAd $channeledAd): self
    {
        if ($this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->removeElement($channeledAd);
            if ($channeledAd->getChanneledAdGroup() === $this) {
                $channeledAd->addChanneledAdGroup(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getMetricConfigs(): Collection
    {
        return $this->metricConfigs;
    }

    /**
     * Adds a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        if (!$this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addChanneledAdGroup($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function removeMetricConfig(MetricConfig $metricConfig): self
    {
        if ($this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->removeElement($metricConfig);
            if ($metricConfig->getChanneledAdGroup() === $this) {
                $metricConfig->addChanneledAdGroup(null);
            }
        }
        return $this;
    }
}
