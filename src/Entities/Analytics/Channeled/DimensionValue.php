<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\Channeled\DimensionValueRepository;
use Entities\Analytics\Channeled\DimensionKey;

#[ORM\Entity(repositoryClass: DimensionValueRepository::class)]
#[ORM\Table(name: 'dimension_values')]
#[ORM\UniqueConstraint(name: 'dimension_value_unique', columns: ['dimension_key_id', 'value'])]
class DimensionValue extends Entity
{
    #[ORM\ManyToOne(targetEntity: DimensionKey::class, inversedBy: 'values')]
    #[ORM\JoinColumn(name: 'dimension_key_id', nullable: false, onDelete: 'CASCADE')]
    protected DimensionKey $dimensionKey;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $value;

    public function getDimensionKey(): DimensionKey
    {
        return $this->dimensionKey;
    }

    public function setDimensionKey(DimensionKey $dimensionKey): self
    {
        $this->dimensionKey = $dimensionKey;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }
}
