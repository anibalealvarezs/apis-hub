<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Anibalealvarezs\ApiSkeleton\Enums\Country as CountryEnum;
use Repositories\CountryRepository;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'countries')]
#[ORM\UniqueConstraint(name: 'country_code_unique', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Country extends Entity
{
    #[ORM\Column(type: 'string', enumType: CountryEnum::class)]
    protected CountryEnum $code;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'country', targetEntity: State::class)]
    protected Collection $states;

    #[ORM\OneToMany(mappedBy: 'country', targetEntity: City::class)]
    protected Collection $cities;

    public function __construct()
    {
        $this->states = new ArrayCollection();
        $this->cities = new ArrayCollection();
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

    public function getStates(): Collection
    {
        return $this->states;
    }

    public function addState(State $state): self
    {
        if (!$this->states->contains($state)) {
            $this->states->add($state);
            $state->addCountry($this);
        }
        return $this;
    }

    public function removeState(State $state): self
    {
        if ($this->states->contains($state)) {
            $this->states->removeElement($state);
            if ($state->getCountry() === $this) {
                $state->addCountry(null);
            }
        }
        return $this;
    }

    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function addCity(City $city): self
    {
        if (!$this->cities->contains($city)) {
            $this->cities->add($city);
            $city->addCountry($this);
        }
        return $this;
    }

    public function removeCity(City $city): self
    {
        if ($this->cities->contains($city)) {
            $this->cities->removeElement($city);
            if ($city->getCountry() === $this) {
                $city->addCountry(null);
            }
        }
        return $this;
    }
}
