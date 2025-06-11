<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\Channeled\ChanneledMetricDimensionRepository;

#[ORM\Entity(repositoryClass: ChanneledMetricDimensionRepository::class)]
#[ORM\Table(name: 'channeled_metric_dimensions')]
#[ORM\Index(
    columns: ['channeledMetric_id', 'dimensionKey', 'dimensionValue'],
    name: 'lookup_by_metric_key_value_idx'
)]
#[ORM\UniqueConstraint(name: 'channeled_metric_dimension_unique', columns: ['dimensionKey', 'dimensionValue', 'channeledMetric_id'])]
#[ORM\Index(columns: ['channeledMetric_id'], name: 'channeledMetric_id_idx')]
#[ORM\Index(columns: ['dimensionKey', 'dimensionValue'], name: 'dimensionKey_dimensionValue_idx')]
#[ORM\Index(columns: ['dimensionKey', 'dimensionValue', 'channeledMetric_id'], name: 'dimensionKey_dimensionValue_channeledMetric_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledMetricDimension extends Entity
{
    #[ORM\ManyToOne(targetEntity: ChanneledMetric::class, inversedBy: 'dimensions')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ChanneledMetric $channeledMetric;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $dimensionKey;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $dimensionValue;

    public function getDimensionKey(): string
    {
        return $this->dimensionKey;
    }

    public function addDimensionKey(string $dimensionKey): self
    {
        $this->dimensionKey = $dimensionKey;
        return $this;
    }

    public function getDimensionValue(): string
    {
        return $this->dimensionValue;
    }

    public function addDimensionValue(string $dimensionValue): self
    {
        $this->dimensionValue = $dimensionValue;
        return $this;
    }

    public function getChanneledMetric(): ChanneledMetric
    {
        return $this->channeledMetric;
    }

    public function addChanneledMetric(?ChanneledMetric $channeledMetric): self
    {
        $this->channeledMetric = $channeledMetric;
        return $this;
    }
}