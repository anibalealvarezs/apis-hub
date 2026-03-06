<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Metric;
use Repositories\Channeled\ChanneledMetricRepository;

#[ORM\Entity(repositoryClass: ChanneledMetricRepository::class)]
#[ORM\Table(name: 'channeled_metrics')]
#[ORM\UniqueConstraint(name: 'channeled_metrics_unique_idx', columns: ['platformId', 'channel', 'metric_id', 'platformCreatedAt'])]
#[ORM\Index(columns: ['metric_id'], name: 'metric_id_idx')]
#[ORM\Index(columns: ['metric_id', 'platformCreatedAt'], name: 'metric_id_platformCreatedAt_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'metric_id'], name: 'platformId_channel_metric_id_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'platformCreatedAt'], name: 'platformId_channel_platformCreatedAt_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledMetric extends ChanneledEntity
{
    #[ORM\ManyToOne(targetEntity: Metric::class, inversedBy: 'channeledMetrics')]
    #[ORM\JoinColumn(name: 'metric_id', onDelete: 'CASCADE')]
    protected Metric $metric;

    #[ORM\OneToMany(mappedBy: 'channeledMetric', targetEntity: ChanneledMetricDimension::class, cascade: ['persist', 'remove'])]
    protected Collection $dimensions;

    public function __construct()
    {
        $this->dimensions = new ArrayCollection();
    }

    /**
     * @return Metric
     */
    public function getMetric(): Metric
    {
        return $this->metric;
    }

    /**
     * @param Metric $metric
     * @return ChanneledMetric
     */
    public function addMetric(Metric $metric): self
    {
        $this->metric = $metric;
        return $this;
    }

    /**
     * @return Collection
     */
    public function getDimensions(): Collection
    {
        return $this->dimensions;
    }

    /**
     * @param ChanneledMetricDimension $dimension
     * @return ChanneledMetric
     */
    public function addDimension(ChanneledMetricDimension $dimension): self
    {
        if (!$this->dimensions->contains($dimension)) {
            $this->dimensions->add($dimension);
            $dimension->addChanneledMetric($this);
        }
        return $this;
    }

    /**
     * @param ArrayCollection $dimensions
     * @return ChanneledMetric
     */
    public function addDimensions(ArrayCollection $dimensions): self
    {
        foreach ($dimensions as $dimension) {
            if (!$this->dimensions->contains($dimension)) {
                $this->addDimension($dimension);
            }
        }
        return $this;
    }

    /**
     * @param ChanneledMetricDimension $dimension
     * @return ChanneledMetric
     */
    public function removeDimension(ChanneledMetricDimension $dimension): self
    {
        if ($this->dimensions->removeElement($dimension)) {
            if ($dimension->getChanneledMetric() === $this) {
                $dimension->addChanneledMetric(null);
            }
        }
        return $this;
    }
}
