<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Account;
use Enums\Account as AccountEnum;
use Repositories\Channeled\ChanneledAccountRepository;

#[ORM\Entity(repositoryClass: ChanneledAccountRepository::class)]
#[ORM\Table(name: 'channeled_accounts')]
#[ORM\Index(columns: ['name', 'platformId', 'channel', 'type'], name: 'name_platformId_channel_type_idx')]
#[ORM\Index(columns: ['name', 'platformId', 'channel'], name: 'name_platformId_channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'type'], name: 'platformId_channel_type_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['name', 'channel', 'type'], name: 'email_channel_type_idx')]
#[ORM\Index(columns: ['name', 'channel'], name: 'email_channel_idx')]
#[ORM\Index(columns: ['platformId', 'type'], name: 'platformId_type_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\Index(columns: ['name', 'type'], name: 'name_type_idx')]
#[ORM\Index(columns: ['name'], name: 'name_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAccount extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string', enumType: AccountEnum::class)]
    protected AccountEnum $type;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'channeledAccounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Account $account;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ChanneledAccount
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function addType(AccountEnum $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): AccountEnum
    {
        return $this->type;
    }

    /**
     * @return Account
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * @param Account|null $account
     * @return ChanneledAccount
     */
    public function addAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }
}