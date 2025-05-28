<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Campaign;
use Entities\Analytics\Metric;
use Repositories\Channeled\ChanneledCampaignRepository;

#[ORM\Entity(repositoryClass: ChanneledCampaignRepository::class)]
#[ORM\Table(name: 'channeled_campaigns')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledCampaign extends ChanneledEntity
{
    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'channeledCampaigns')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    protected Campaign $campaign;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: ChanneledAdGroup::class)]
    protected Collection $channeledAdGroups;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: ChanneledAd::class)]
    protected Collection $channeledAds;

    #[ORM\OneToMany(mappedBy: 'channeledCampaign', targetEntity: Metric::class)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->channeledAdGroups = new ArrayCollection();
        $this->metrics = new ArrayCollection();
        $this->channeledAds = new ArrayCollection();
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
     * Gets the collection of metrics.
     * @return Collection
     */
    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    /**
     * Adds a metric.
     * @param Metric $metric
     * @return self
     */
    public function addMetric(Metric $metric): self
    {
        if (!$this->metrics->contains($metric)) {
            $this->metrics->add($metric);
            $metric->addChanneledCampaign($this);
        }
        return $this;
    }

    /**
     * Removes a metric.
     * @param Metric $metric
     * @return self
     */
    public function removeMetric(Metric $metric): self
    {
        if ($this->metrics->contains($metric)) {
            $this->metrics->removeElement($metric);
            if ($metric->getChanneledCampaign() === $this) {
                $metric->addChanneledCampaign(null);
            }
        }
        return $this;
    }
}