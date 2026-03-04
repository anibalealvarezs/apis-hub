<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Creative;
use Entities\Analytics\MetricConfig;
use Enums\CampaignStatus;
use Repositories\Channeled\ChanneledAdRepository;

#[ORM\Entity(repositoryClass: ChanneledAdRepository::class)]
#[ORM\Table(name: 'channeled_ads')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['channel'], name: 'channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'channel_idx')]
#[ORM\Index(columns: ['channeledAdGroup_id'], name: 'channeledAdGroup_id_idx')]
#[ORM\Index(columns: ['channeledCampaign_id'], name: 'channeledCampaign_id_idx')]
#[ORM\Index(columns: ['channeledCampaign_id', 'channeledAdGroup_id'], name: 'channeledCampaign_id_channeledAdGroup_id_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'channeledCampaign_id', 'channeledAdGroup_id'], name: 'platformId_channel_channeledCampaign_id_channeledAdGroup_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAd extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string', nullable: true, enumType: CampaignStatus::class)]
    protected ?CampaignStatus $status = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAdGroup::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAdGroup $channeledAdGroup = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\ManyToOne(targetEntity: Creative::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Creative $creative = null;

    #[ORM\OneToMany(mappedBy: 'channeledAd', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * Gets the ad name.
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the ad name.
     * @param string $name
     * @return self
     */
    public function addName(string $name): self
    {
        $this->name = $name;
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
     * Gets the associated ad group.
     * @return ChanneledAdGroup|null
     */
    public function getChanneledAdGroup(): ?ChanneledAdGroup
    {
        return $this->channeledAdGroup;
    }

    /**
     * Sets the associated ad group.
     * @param ChanneledAdGroup|null $channeledAdGroup
     * @return self
     */
    public function addChanneledAdGroup(?ChanneledAdGroup $channeledAdGroup): self
    {
        $this->channeledAdGroup = $channeledAdGroup;
        return $this;
    }

    /**
     * Gets the associated platform-specific campaign.
     * @return ChanneledCampaign|null
     */
    public function getChanneledCampaign(): ?ChanneledCampaign
    {
        return $this->channeledCampaign;
    }

    /**
     * Sets the associated platform-specific campaign.
     * @param ChanneledCampaign|null $channeledCampaign
     * @return self
     */
    public function addChanneledCampaign(?ChanneledCampaign $channeledCampaign): self
    {
        $this->channeledCampaign = $channeledCampaign;
        return $this;
    }

    /**
     * Gets the associated creative.
     * @return Creative|null
     */
    public function getCreative(): ?Creative
    {
        return $this->creative;
    }

    /**
     * Sets the associated creative.
     * @param Creative|null $creative
     * @return self
     */
    public function addCreative(?Creative $creative): self
    {
        $this->creative = $creative;
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
            $metricConfig->addChanneledAd($this);
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
            if ($metricConfig->getChanneledAd() === $this) {
                $metricConfig->addChanneledAd(null);
            }
        }
        return $this;
    }
}
