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
#[ORM\Index(columns: ['orderId'], name: 'idx_orders_orderId_idx')]
#[ORM\HasLifecycleCallbacks]
class Order extends Entity
{
    #[ORM\Column(name: 'order_id', type: 'string', unique: true)]
    protected int|string $orderId;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: ChanneledOrder::class, orphanRemoval: true)]
    protected Collection $channeledOrders;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
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

    /**
     * @return Collection|null
     */
    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return Order
     */
    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->add($channeledOrder);
        $channeledOrder->addOrder($this);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return Order
     */
    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return Order
     */
    public function removeChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if (!$this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->removeElement($channeledOrder);

        if ($channeledOrder->getOrder() !== $this) {
            return $this;
        }

        $channeledOrder->addOrder(order: null);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return Order
     */
    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getMetricConfigs(): Collection
    {
        return $this->metricConfigs;
    }

    /**
     * Adds a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        if (!$this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addOrder($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function removeMetricConfig(MetricConfig $metricConfig): self
    {
        if ($this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->removeElement($metricConfig);
            if ($metricConfig->getOrder() === $this) {
                $metricConfig->addOrder(null);
            }
        }
        return $this;
    }
}
