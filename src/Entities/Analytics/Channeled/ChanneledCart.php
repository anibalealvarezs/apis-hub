<?php

namespace Entities\Analytics\Channeled;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'channeled_carts')]
#[ORM\UniqueConstraint(name: 'channeled_carts_source_unique', columns: ['platform_id', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledCart extends ChanneledEntity
{
    #[ORM\ManyToOne(targetEntity: ChanneledStore::class)]
    #[ORM\JoinColumn(name: 'channeled_store_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?ChanneledStore $channeledStore = null;

    public function getChanneledStore(): ?ChanneledStore
    {
        return $this->channeledStore;
    }
}
