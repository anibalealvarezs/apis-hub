<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledOrder;
use Entities\Entity;
use Repositories\OrderRepository;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order extends Entity
{
    #[ORM\Column(type: 'int', unique: true)]
    protected string $orderId;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: ChanneledOrder::class, orphanRemoval: true)]
    protected Collection $channeledOrders;

    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getOrderId(): string
    {
        return $this->orderId;
    }

    /**
     * @param string $orderId
     */
    public function addOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        $this->channeledOrders->add($channeledOrder);

        return $this;
    }

    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function removeChanneledOrder(ChanneledOrder $channeledOrder): void
    {
        $this->channeledOrders->removeElement($channeledOrder);
    }

    public function removeChanneledOrders(Collection $channeledOrders): void
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }
    }
}