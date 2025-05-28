<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Creative;
use Entities\Analytics\Metric;
use Repositories\Channeled\ChanneledAdRepository;

#[ORM\Entity(repositoryClass: ChanneledAdRepository::class)]
#[ORM\Table(name: 'channeled_ads')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAd extends ChanneledEntity
{
    protected string $name;

    #[ORM\ManyToOne(targetEntity: ChanneledAdGroup::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAdGroup $channeledAdGroup = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\ManyToOne(targetEntity: Creative::class, inversedBy: 'channeledAds')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Creative $creative = null;

    #[ORM\OneToMany(mappedBy: 'channeledAd', targetEntity: Metric::class)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
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
            $metric->addChanneledAd($this);
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
            if ($metric->getChanneledAd() === $this) {
                $metric->addChanneledAd(null);
            }
        }
        return $this;
    }
}