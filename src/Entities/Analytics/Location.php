<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Entity;
use Repositories\LocationRepository;

#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
#[ORM\Index(columns: ['platform_id'], name: 'idx_locations_platform_id_idx')]
#[ORM\Index(columns: ['platform_id', 'account_id'], name: 'idx_locations_platform_account_idx')]
#[ORM\Index(columns: ['platform_id', 'channeled_account_id'], name: 'idx_locations_platform_caccount_idx')]
#[ORM\Index(columns: ['city_id'], name: 'idx_locations_city_idx')]
#[ORM\Index(columns: ['state_id'], name: 'idx_locations_state_idx')]
#[ORM\Index(columns: ['country_id'], name: 'idx_locations_country_idx')]
#[ORM\UniqueConstraint(name: 'location_platform_unique', columns: ['platform_id'])]
#[ORM\HasLifecycleCallbacks]
class Location extends Entity
{
    #[ORM\Column(name: 'platform_id', type: 'string')]
    protected string $platformId;

    #[ORM\Column(type: 'string')]
    protected string $title;

    #[ORM\Column(name: 'store_code', type: 'string', nullable: true)]
    protected ?string $storeCode = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $lat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $lng = null;

    #[ORM\Column(name: 'zip_code', type: 'string', nullable: true)]
    protected ?string $zipCode = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'locations')]
    #[ORM\JoinColumn(name: 'account_id', onDelete: 'SET NULL')]
    protected ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'locations')]
    #[ORM\JoinColumn(name: 'channeled_account_id', onDelete: 'SET NULL')]
    protected ?ChanneledAccount $channeledAccount = null;

    #[ORM\ManyToOne(targetEntity: City::class, inversedBy: 'locations')]
    #[ORM\JoinColumn(name: 'city_id', onDelete: 'SET NULL')]
    protected ?City $city = null;

    #[ORM\ManyToOne(targetEntity: State::class, inversedBy: 'locations')]
    #[ORM\JoinColumn(name: 'state_id', onDelete: 'SET NULL')]
    protected ?State $state = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_id', onDelete: 'SET NULL')]
    protected ?Country $country = null;

    #[ORM\OneToMany(mappedBy: 'location', targetEntity: MetricConfig::class)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
    }

    public function getPlatformId(): string
    {
        return $this->platformId;
    }

    public function addPlatformId(string $platformId): self
    {
        $this->platformId = $platformId;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function addTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getStoreCode(): ?string
    {
        return $this->storeCode;
    }

    public function addStoreCode(?string $storeCode): self
    {
        $this->storeCode = $storeCode;
        return $this;
    }

    public function getLat(): ?float
    {
        return $this->lat;
    }

    public function addLat(?float $lat): self
    {
        $this->lat = $lat;
        return $this;
    }

    public function getLng(): ?float
    {
        return $this->lng;
    }

    public function addLng(?float $lng): self
    {
        $this->lng = $lng;
        return $this;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function addZipCode(?string $zipCode): self
    {
        $this->zipCode = $zipCode;
        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function addData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function addAccount(?Account $account): self
    {
        $this->account = $account;
        return $this;
    }

    public function getChanneledAccount(): ?ChanneledAccount
    {
        return $this->channeledAccount;
    }

    public function addChanneledAccount(?ChanneledAccount $channeledAccount): self
    {
        $this->channeledAccount = $channeledAccount;
        return $this;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function addCity(?City $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getState(): ?State
    {
        return $this->state;
    }

    public function addState(?State $state): self
    {
        $this->state = $state;
        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function addCountry(?Country $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getMetricConfigs(): Collection
    {
        return $this->metricConfigs;
    }

    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        if (!$this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addLocation($this);
        }
        return $this;
    }

    public function removeMetricConfig(MetricConfig $metricConfig): self
    {
        if ($this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->removeElement($metricConfig);
            if ($metricConfig->getLocation() === $this) {
                $metricConfig->addLocation(null);
            }
        }
        return $this;
    }
}
