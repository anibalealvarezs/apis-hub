<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Entity;
use Repositories\AccountRepository;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(columns: ['name'], name: 'name_idx')]
#[ORM\HasLifecycleCallbacks]
class Account extends Entity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'vendor', targetEntity: ChanneledAccount::class, orphanRemoval: true)]
    protected Collection $channeledAccounts;

    public function __construct()
    {
        $this->channeledAccounts = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Account
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledAccounts(): ?Collection
    {
        return $this->channeledAccounts;
    }

    /**
     * @param ChanneledAccount $channeledAccount
     * @return Account
     */
    public function addChanneledAccount(ChanneledAccount $channeledAccount): self
    {
        if ($this->channeledAccounts->contains($channeledAccount)) {
            return $this;
        }

        $this->channeledAccounts->add($channeledAccount);
        $channeledAccount->addAccount($this);

        return $this;
    }

    /**
     * @param Collection $channeledAccounts
     * @return Account
     */
    public function addChanneledAccounts(Collection $channeledAccounts): self
    {
        foreach ($channeledAccounts as $channeledAccount) {
            $this->addChanneledAccount($channeledAccount);
        }

        return $this;
    }

    /**
     * @param ChanneledAccount $channeledAccount
     * @return Account
     */
    public function removeChanneledAccount(ChanneledAccount $channeledAccount): self
    {
        if (!$this->channeledAccounts->contains($channeledAccount)) {
            return $this;
        }

        $this->channeledAccounts->removeElement($channeledAccount);

        if ($channeledAccount->getAccount() !== $this) {
            return $this;
        }

        $channeledAccount->addAccount(account: null);

        return $this;
    }

    /**
     * @param Collection $channeledAccounts
     * @return Account
     */
    public function removeChanneledAccounts(Collection $channeledAccounts): self
    {
        foreach ($channeledAccounts as $channeledAccount) {
            $this->removeChanneledAccount($channeledAccount);
        }

        return $this;
    }
}