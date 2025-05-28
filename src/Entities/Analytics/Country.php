<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Enums\Country as CountryEnum;

#[ORM\Entity]
#[ORM\Table(name: 'countries')]
#[ORM\UniqueConstraint(name: 'country_code_unique', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Country extends Entity
{
    #[ORM\Column(type: 'string', enumType: CountryEnum::class)]
    protected CountryEnum $code;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'query', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    public function addCode(CountryEnum $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getCode(): CountryEnum
    {
        return $this->code;
    }

    public function addName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }
}