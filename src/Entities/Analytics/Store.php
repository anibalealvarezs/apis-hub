<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledStore;
use Entities\Entity;
use Repositories\StoreRepository;

#[ORM\Entity(repositoryClass: StoreRepository::class)]
#[ORM\Table(name: 'stores')]
#[ORM\Index(columns: ['canonical_id'], name: 'idx_stores_canonical_id_idx')]
#[ORM\Index(columns: ['domain'], name: 'idx_stores_domain_idx')]
#[ORM\HasLifecycleCallbacks]
class Store extends Entity
{
    #[ORM\Column(name: 'canonical_id', type: 'string', unique: true, nullable: true)]
    protected ?string $canonicalId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $domain = null;

    #[ORM\Column(name: 'currency_code', type: 'string', length: 10, nullable: true)]
    protected ?string $currencyCode = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\OneToMany(mappedBy: 'store', targetEntity: ChanneledStore::class, orphanRemoval: true)]
    protected Collection $channeledStores;

    public function __construct()
    {
        $this->channeledStores = new ArrayCollection();
    }

    public function getCanonicalId(): ?string
    {
        return $this->canonicalId;
    }

    public function setCanonicalId(?string $canonicalId): self
    {
        $this->canonicalId = $canonicalId;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getCurrencyCode(): ?string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(?string $currencyCode): self
    {
        $this->currencyCode = $currencyCode;
        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getChanneledStores(): Collection
    {
        return $this->channeledStores;
    }
}
