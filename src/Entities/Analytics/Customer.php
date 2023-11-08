<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\CustomerRepository;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
#[ORM\HasLifecycleCallbacks]
class Customer extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: 'Order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $orders;

    /**
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     */
    public function addPlatformId(int|string $platformId): void
    {
        $this->platformId = $platformId;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @param int $channel
     */
    public function addChannel(int $channel): void
    {
        $this->channel = $channel;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param string $data
     */
    public function addData(string $data): void
    {
        $this->data = $data;
    }

    public function getOrders(): ?Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        $this->orders->add($order);

        return $this;
    }

    public function addOrders(Collection $orders): self
    {
        foreach ($orders as $order) {
            $this->addOrder($order);
        }

        return $this;
    }

    public function removeOrder(Order $order): void
    {
        $this->orders->removeElement($order);
    }

    public function removeOrders(Collection $orders): void
    {
        foreach ($orders as $order) {
            $this->removeOrder($order);
        }
    }
}