<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Entity;
use Repositories\MetricRepository;

#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: 'metrics')]
#[ORM\Index(
    columns: ['metric_config_id', 'dimensions_hash'],
    name: 'idx_metrics_metric_config_dimensions_hash_lookup_idx'
)]
#[ORM\Index(
    columns: ['dimensions_hash'],
    name: 'idx_metrics_dimensions_hash_lookup_idx'
)]
#[ORM\Index(
    columns: ['metric_config_id'],
    name: 'idx_metrics_metric_config_lookup_idx'
)]
#[ORM\UniqueConstraint(name: 'metric_unique', columns: ['metric_config_id', 'dimensions_hash', 'metric_date'])]
#[ORM\HasLifecycleCallbacks]
class Metric extends Entity
{
    #[ORM\ManyToOne(targetEntity: MetricConfig::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(name: 'metric_config_id', onDelete: 'CASCADE')]
    protected MetricConfig $metricConfig;

    #[ORM\Column(name: 'dimensions_hash', type: 'string')]
    protected string $dimensionsHash;

    #[ORM\Column(type: 'float')]
    protected float $value;

    #[ORM\OneToMany(mappedBy: 'metric', targetEntity: ChanneledMetric::class, orphanRemoval: true)]
    protected Collection $channeledMetrics;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $metadata = [];

    #[ORM\Column(name: 'metric_date', type: 'date')]
    protected \DateTimeInterface $metricDate;

    /**
     * @return MetricConfig
     */
    public function getMetricConfig(): MetricConfig
    {
        return $this->metricConfig;
    }

    /**
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        $this->metricConfig = $metricConfig;
        return $this;
    }

    /**
     * @return string
     */
    public function getDimensionsHash(): string
    {
        return $this->dimensionsHash;
    }

    /**
     * @param string $dimensionsHash
     * @return self
     */
    public function addDimensionsHash(string $dimensionsHash): self
    {
        $this->dimensionsHash = $dimensionsHash;
        return $this;
    }

    /**
     * @return float
     */
    public function getValue(): float
    {
        return $this->value;
    }

    /**
     * @param float $value
     * @return self
     */
    public function addValue(float $value): self
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array|null $metadata
     * @return self
     */
    public function addMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getMetricDate(): \DateTimeInterface
    {
        return $this->metricDate;
    }

    /**
     * @param \DateTimeInterface $metricDate
     * @return self
     */
    public function addMetricDate(\DateTimeInterface $metricDate): self
    {
        $this->metricDate = $metricDate;
        return $this;
    }
}
