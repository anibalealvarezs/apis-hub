<?php

namespace Entities\Analytics;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Entity;
use Repositories\CampaignRepository;

#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaigns')]
#[ORM\Index(columns: ['campaign_id'], name: 'idx_campaigns_campaign_id_idx')]
#[ORM\HasLifecycleCallbacks]
class Campaign extends Entity
{
    #[ORM\Column(name: 'campaign_id', type: 'string', unique: true)]
    protected string $campaignId;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(name: 'start_date', type: 'datetime', nullable: true)]
    protected ?DateTime $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'datetime', nullable: true)]
    protected ?DateTime $endDate = null;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: ChanneledCampaign::class, orphanRemoval: true)]
    protected Collection $channeledCampaigns;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: ChanneledAdGroup::class)]
    protected Collection $channeledAdGroups;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledCampaigns = new ArrayCollection();
        $this->channeledAdGroups = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * Gets the unique campaign identifier.
     * @return string
     */
    public function getCampaignId(): string
    {
        return $this->campaignId;
    }

    /**
     * Sets the unique campaign identifier.
     * @param string $campaignId
     * @return self
     */
    public function addCampaignId(string $campaignId): self
    {
        $this->campaignId = $campaignId;
        return $this;
    }

    /**
     * Gets the campaign name.
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the campaign name.
     * @param string $name
     * @return self
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
     * Gets the collection of platform-specific campaigns.
     * @return Collection
     */
    public function getChanneledCampaigns(): Collection
    {
        return $this->channeledCampaigns;
    }

    /**
     * Adds a platform-specific campaign.
     * @param ChanneledCampaign $channeledCampaign
     * @return self
     */
    public function addChanneledCampaign(ChanneledCampaign $channeledCampaign): self
    {
        if (!$this->channeledCampaigns->contains($channeledCampaign)) {
            $this->channeledCampaigns->add($channeledCampaign);
            $channeledCampaign->addCampaign($this);
        }
        return $this;
    }

    /**
     * Removes a platform-specific campaign.
     * @param ChanneledCampaign $channeledCampaign
     * @return self
     */
    public function removeChanneledCampaign(ChanneledCampaign $channeledCampaign): self
    {
        if ($this->channeledCampaigns->contains($channeledCampaign)) {
            $this->channeledCampaigns->removeElement($channeledCampaign);
            if ($channeledCampaign->getCampaign() === $this) {
                $channeledCampaign->addCampaign(null);
            }
        }
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
            $channeledAdGroup->addCampaign($this);
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
            if ($channeledAdGroup->getCampaign() === $this) {
                $channeledAdGroup->addCampaign(null);
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
            $metricConfig->addCampaign($this);
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
            if ($metricConfig->getCampaign() === $this) {
                $metricConfig->addCampaign(null);
            }
        }
        return $this;
    }
}
