<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\Channeled\DimensionSetRepository;
use Entities\Analytics\Channeled\DimensionValue;

#[ORM\Entity(repositoryClass: DimensionSetRepository::class)]
#[ORM\Table(name: 'dimension_sets')]
class DimensionSet extends Entity
{
    #[ORM\Column(type: 'string', length: 32, unique: true)]
    protected string $hash;

    #[ORM\ManyToMany(targetEntity: DimensionValue::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'dimension_set_items')]
    #[ORM\JoinColumn(name: 'dimension_set_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'dimension_value_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected Collection $values;

    #[ORM\OneToMany(mappedBy: 'dimensionSet', targetEntity: ChanneledMetric::class)]
    protected Collection $channeledMetrics;

    public function __construct()
    {
        $this->values = new ArrayCollection();
        $this->channeledMetrics = new ArrayCollection();
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }

    /**
     * @return Collection
     */
    public function getValues(): Collection
    {
        return $this->values;
    }

    public function addValue(DimensionValue $value): self
    {
        if (!$this->values->contains($value)) {
            $this->values->add($value);
        }
        return $this;
    }

    /**
     * @return Collection
     */
    public function getChanneledMetrics(): Collection
    {
        return $this->channeledMetrics;
    }
}
