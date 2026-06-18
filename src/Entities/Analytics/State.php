<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\StateRepository;

#[ORM\Entity(repositoryClass: StateRepository::class)]
#[ORM\Table(name: 'states')]
#[ORM\UniqueConstraint(name: 'state_name_country_unique', columns: ['name', 'country_id'])]
#[ORM\HasLifecycleCallbacks]
class State extends Entity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $code = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'states')]
    #[ORM\JoinColumn(name: 'country_id', onDelete: 'CASCADE')]
    protected Country $country;

    #[ORM\OneToMany(mappedBy: 'state', targetEntity: City::class, orphanRemoval: true)]
    protected Collection $cities;

    #[ORM\OneToMany(mappedBy: 'state', targetEntity: Location::class)]
    protected Collection $locations;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
        $this->locations = new ArrayCollection();
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

    public function addCode(?string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function addCountry(?Country $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function addCity(City $city): self
    {
        if (!$this->cities->contains($city)) {
            $this->cities->add($city);
            $city->addState($this);
        }
        return $this;
    }

    public function removeCity(City $city): self
    {
        if ($this->cities->contains($city)) {
            $this->cities->removeElement($city);
            if ($city->getState() === $this) {
                $city->addState(null);
            }
        }
        return $this;
    }

    public function getLocations(): Collection
    {
        return $this->locations;
    }

    public function addLocation(Location $location): self
    {
        if (!$this->locations->contains($location)) {
            $this->locations->add($location);
            $location->addState($this);
        }
        return $this;
    }

    public function removeLocation(Location $location): self
    {
        if ($this->locations->contains($location)) {
            $this->locations->removeElement($location);
            if ($location->getState() === $this) {
                $location->addState(null);
            }
        }
        return $this;
    }
}
