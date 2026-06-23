<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\CityRepository;

#[ORM\Entity(repositoryClass: CityRepository::class)]
#[ORM\Table(name: 'cities')]
#[ORM\UniqueConstraint(name: 'city_name_country_unique', columns: ['name', 'country_id'])]
#[ORM\HasLifecycleCallbacks]
class City extends Entity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\ManyToOne(targetEntity: State::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(name: 'state_id', onDelete: 'SET NULL')]
    protected ?State $state = null;

    #[ORM\ManyToOne(targetEntity: Country::class, inversedBy: 'cities')]
    #[ORM\JoinColumn(name: 'country_id', onDelete: 'CASCADE')]
    protected Country $country;

    #[ORM\OneToMany(mappedBy: 'city', targetEntity: Location::class)]
    protected Collection $locations;

    public function __construct()
    {
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

    public function addState(?State $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function getState(): ?State
    {
        return $this->state;
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

    public function getLocations(): Collection
    {
        return $this->locations;
    }

    public function addLocation(Location $location): self
    {
        if (!$this->locations->contains($location)) {
            $this->locations->add($location);
            $location->addCity($this);
        }
        return $this;
    }

    public function removeLocation(Location $location): self
    {
        if ($this->locations->contains($location)) {
            $this->locations->removeElement($location);
            if ($location->getCity() === $this) {
                $location->addCity(null);
            }
        }
        return $this;
    }
}
