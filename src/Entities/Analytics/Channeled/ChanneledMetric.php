<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Metric;
use Repositories\Channeled\ChanneledMetricRepository;

#[ORM\Entity(repositoryClass: ChanneledMetricRepository::class)]
#[ORM\Table(name: 'channeled_metrics')]
#[ORM\UniqueConstraint(name: 'idx_channeled_metrics_full_unique', columns: ['platform_id', 'channel', 'metric_id', 'platform_created_at'])]
#[ORM\Index(columns: ['metric_id'], name: 'idx_channeled_metrics_metric_id_idx')]
#[ORM\Index(columns: ['metric_id', 'platform_created_at'], name: 'idx_channeled_metrics_metric_created_idx')]
#[ORM\Index(columns: ['platform_id', 'channel'], name: 'idx_channeled_metrics_platform_channel_idx')]
#[ORM\Index(columns: ['platform_id', 'channel', 'metric_id'], name: 'idx_channeled_metrics_full_idx')]
#[ORM\Index(columns: ['platform_id', 'channel', 'platform_created_at'], name: 'idx_channeled_metrics_platform_created_idx')]
#[ORM\Index(columns: ['platform_created_at'], name: 'idx_channeled_metrics_created_at_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledMetric extends ChanneledEntity
{
    #[ORM\ManyToOne(targetEntity: Metric::class, inversedBy: 'channeledMetrics')]
    #[ORM\JoinColumn(name: 'metric_id', onDelete: 'CASCADE')]
    protected Metric $metric;

    #[ORM\ManyToOne(targetEntity: DimensionSet::class, inversedBy: 'channeledMetrics')]
    #[ORM\JoinColumn(name: 'dimension_set_id', nullable: true, onDelete: 'SET NULL')]
    protected ?DimensionSet $dimensionSet = null;

    public function __construct()
    {
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

    public function getDimensionSet(): ?DimensionSet
    {
        return $this->dimensionSet;
    }

    public function setDimensionSet(?DimensionSet $dimensionSet): self
    {
        $this->dimensionSet = $dimensionSet;
        return $this;
    }
}
