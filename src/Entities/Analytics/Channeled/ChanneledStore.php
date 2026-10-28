<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Account;
use Entities\Analytics\Store;
use Repositories\Channeled\ChanneledStoreRepository;

#[ORM\Entity(repositoryClass: ChanneledStoreRepository::class)]
#[ORM\Table(name: 'channeled_stores')]
#[ORM\UniqueConstraint(name: 'channeled_stores_platform_channel_unique', columns: ['platform_id', 'channel'])]
#[ORM\Index(name: 'idx_chs_platform_id_idx', columns: ['platform_id'])]
#[ORM\Index(name: 'idx_chs_domain_idx', columns: ['domain'])]
#[ORM\Index(name: 'idx_chs_channel_idx', columns: ['channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledStore extends ChanneledEntity
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $domain = null;

    #[ORM\Column(name: 'currency_code', type: 'string', length: 10, nullable: true)]
    protected ?string $currencyCode = null;

    #[ORM\ManyToOne(targetEntity: Store::class, inversedBy: 'channeledStores')]
    #[ORM\JoinColumn(name: 'store_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Store $store = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    protected ?Account $account = null;

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

    public function getStore(): ?Store
    {
        return $this->store;
    }

    public function setStore(?Store $store): self
    {
        $this->store = $store;
        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;
        return $this;
    }
}
