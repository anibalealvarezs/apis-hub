<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Campaign;
use Entities\Analytics\MetricConfig;
use Enums\CampaignBuyingType;
use Enums\CampaignObjective;
use Enums\CampaignStatus;
use Repositories\Channeled\ChanneledCampaignRepository;

#[ORM\Entity(repositoryClass: ChanneledCampaignRepository::class)]
#[ORM\Table(name: 'channeled_campaigns')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['channel'], name: 'channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['channeledAccount_id'], name: 'channeledAccount_id_idx')]
#[ORM\Index(columns: ['campaign_id'], name: 'campaign_id_idx')]
#[ORM\Index(columns: ['channeledAccount_id', 'campaign_id'], name: 'channeledAccount_id_campaign_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledCampaign extends ChanneledEntity
{
    #[ORM\Column(type: 'string', nullable: true, enumType: CampaignObjective::class)]
    protected ?CampaignObjective $objective = null;

    #[ORM\Column(type: 'float')]
    protected float $budget;

    #[ORM\Column(type: 'string', nullable: true, enumType: CampaignStatus::class)]
    protected ?CampaignStatus $status = null;

    #[ORM\Column(type: 'string', nullable: true, enumType: CampaignBuyingType::class)]
    protected ?CampaignBuyingType $buyingType = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'channeledCampaigns')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    protected Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'channeledCampaigns')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    protected ChanneledAccount $channeledAccount;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: ChanneledAdGroup::class)]
    protected Collection $channeledAdGroups;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: ChanneledAd::class)]
    protected Collection $channeledAds;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledAdGroups = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
        $this->channeledAds = new ArrayCollection();
    }

    /**
     * Gets the unique campaign identifier.
     * @return CampaignObjective|null
     */
    public function getObjective(): ?CampaignObjective
    {
        return $this->objective;
    }

    /**
     * Sets the unique campaign identifier.
     * @param CampaignObjective|null $objective
     * @return self
     */
    public function addObjective(?CampaignObjective $objective): self
    {
        $this->objective = $objective;
        return $this;
    }

    /**
     * Gets the unique campaign identifier.
     * @return float
     */
    public function getBuget(): float
    {
        return $this->budget;
    }

    /**
     * Sets the unique campaign identifier.
     * @param float $budget
     * @return self
     */
    public function addBudget(float $budget): self
    {
        $this->budget = $budget;
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
     * @return CampaignBuyingType|null
     */
    public function getBuyingType(): ?CampaignBuyingType
    {
        return $this->buyingType;
    }

    /**
     * Sets the unique campaign identifier.
     * @param CampaignBuyingType|null $buyingType
     * @return self
     */
    public function addBuyingType(?CampaignBuyingType $buyingType): self
    {
        $this->buyingType = $buyingType;
        return $this;
    }

    /**
     * Gets the associated global campaign.
     * @return Campaign
     */
    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    /**
     * Sets the associated global campaign.
     * @param Campaign|null $campaign
     * @return self
     */
    public function addCampaign(?Campaign $campaign): self
    {
        $this->campaign = $campaign;
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
     * Gets the collection of platform-specific ad groups.
     * @return Collection
     */
    public function getChanneledAdGroups(): Collection
    {
        return $this->channeledAdGroups;
    }

    /**
     * Adds a platform-specific ad group.
     * @param ChanneledAdGroup $channeledAdGroup
     * @return self
     */
    public function addChanneledAdGroup(ChanneledAdGroup $channeledAdGroup): self
    {
        if (!$this->channeledAdGroups->contains($channeledAdGroup)) {
            $this->channeledAdGroups->add($channeledAdGroup);
            $channeledAdGroup->addChanneledCampaign($this);
        }
        return $this;
    }

    /**
     * Removes a platform-specific ad group.
     * @param ChanneledAdGroup $channeledAdGroup
     * @return self
     */
    public function removeChanneledAdGroup(ChanneledAdGroup $channeledAdGroup): self
    {
        if ($this->channeledAdGroups->contains($channeledAdGroup)) {
            $this->channeledAdGroups->removeElement($channeledAdGroup);
            if ($channeledAdGroup->getChanneledCampaign() === $this) {
                $channeledAdGroup->addChanneledCampaign(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of platform-specific ad groups.
     * @return Collection
     */
    public function getChanneledAds(): Collection
    {
        return $this->channeledAds;
    }

    /**
     * Adds a platform-specific ad group.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function addChanneledAd(ChanneledAd $channeledAd): self
    {
        if (!$this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->add($channeledAd);
            $channeledAd->addChanneledCampaign($this);
        }
        return $this;
    }

    /**
     * Removes a platform-specific ad group.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function removeChanneledAd(ChanneledAd $channeledAd): self
    {
        if ($this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->removeElement($channeledAd);
            if ($channeledAd->getChanneledCampaign() === $this) {
                $channeledAd->addChanneledCampaign(null);
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
            $metricConfig->addChanneledCampaign($this);
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
            if ($metricConfig->getChanneledCampaign() === $this) {
                $metricConfig->addChanneledCampaign(null);
            }
        }
        return $this;
    }
}
