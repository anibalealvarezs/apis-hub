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
#[ORM\Index(columns: ['orderId'])]
#[ORM\HasLifecycleCallbacks]
class Order extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected int|string $orderId;

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
     * @return Order
     */
    public function addOrderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if (!$this->channeledOrders->contains($channeledOrder)) {
            $this->channeledOrders->add($channeledOrder);
            $channeledOrder->addOrder($this);
        }

        return $this;
    }

    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function removeChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            $this->channeledOrders->removeElement($channeledOrder);
            if ($channeledOrder->getOrder() === $this) {
                $channeledOrder->addOrder(null);
            }
        }

        return $this;
    }

    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }
}