<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Campaign;
use Entities\Analytics\Metric;
use Repositories\Channeled\ChanneledAdGroupRepository;

#[ORM\Entity(repositoryClass: ChanneledAdGroupRepository::class)]
#[ORM\Table(name: 'channeled_ad_groups')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAdGroup extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'channeledAdGroups')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'channeledAdGroups')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\OneToMany(mappedBy: 'channeledAdGroup', targetEntity: ChanneledAd::class)]
    protected Collection $channeledAds;

    #[ORM\OneToMany(mappedBy: 'channeledAdGroup', targetEntity: Metric::class)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->channeledAds = new ArrayCollection();
        $this->metrics = new ArrayCollection();
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
     * @return Collection<int, Metric>
     */
    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    /**
     * @param Metric $metric
     * @return ChanneledAdGroup
     */
    public function addMetric(Metric $metric): self
    {
        if (!$this->metrics->contains($metric)) {
            $this->metrics->add($metric);
            $metric->addChanneledAdGroup($this);
        }
        return $this;
    }

    /**
     * @param Metric $metric
     * @return ChanneledAdGroup
     */
    public function removeMetric(Metric $metric): self
    {
        if ($this->metrics->contains($metric)) {
            $this->metrics->removeElement($metric);
            if ($metric->getChanneledAdGroup() === $this) {
                $metric->addChanneledAdGroup(null);
            }
        }
        return $this;
    }
}