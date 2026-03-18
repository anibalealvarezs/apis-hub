<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\Channeled\DimensionKeyRepository;
use Entities\Analytics\Channeled\DimensionValue;

#[ORM\Entity(repositoryClass: DimensionKeyRepository::class)]
#[ORM\Table(name: 'dimension_keys')]
class DimensionKey extends Entity
{
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'dimensionKey', targetEntity: 'Entities\Analytics\Channeled\DimensionValue')]
    protected Collection $values;

    public function __construct()
    {
        $this->values = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return Collection
     */
    public function getValues(): Collection
    {
        return $this->values;
    }
}
