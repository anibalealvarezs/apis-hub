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
    columns: ['metricConfig_id', 'dimensionsHash'],
    name: 'metricConfig_dimensionsHash_lookup_idx'
)]
#[ORM\Index(
    columns: ['dimensionsHash'],
    name: 'dimensionsHash_lookup_idx'
)]
#[ORM\Index(
    columns: ['metricConfig_id'],
    name: 'metricConfig_lookup_idx'
)]
#[ORM\UniqueConstraint(name: 'metric_unique', columns: ['metricConfig_id', 'dimensionsHash'])]
#[ORM\HasLifecycleCallbacks]
class Metric extends Entity
{
    #[ORM\ManyToOne(targetEntity: MetricConfig::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(name: 'metricConfig_id', onDelete: 'CASCADE')]
    protected MetricConfig $metricConfig;

    #[ORM\Column(type: 'string')]
    protected string $dimensionsHash;

    #[ORM\Column(type: 'float')]
    protected float $value;

    #[ORM\OneToMany(mappedBy: 'metric', targetEntity: ChanneledMetric::class, orphanRemoval: true)]
    protected Collection $channeledMetrics;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $metadata = [];

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
}
