<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\DiscountRepository;

#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table(name: 'discounts')]
#[ORM\HasLifecycleCallbacks]
class Discount extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    // Many Products have Many Orders.
    #[ORM\ManyToMany(targetEntity: 'Order', mappedBy: 'discounts')]
    protected Collection $orders;

    #[ORM\ManyToOne(targetEntity: "PriceRule", cascade: ['persist'], inversedBy: 'priceRules')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected PriceRule $priceRule;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
    }

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

    /**
     * @return Collection|null
     */
    public function getOrders(): ?Collection
    {
        return $this->orders;
    }

    /**
     * @param Order $order
     * @return Discount
     */
    public function addOrder(Order $order): self
    {
        $this->orders->add($order);

        return $this;
    }

    /**
     * @param Collection $orders
     * @return Discount
     */
    public function addOrders(Collection $orders): self
    {
        foreach ($orders as $order) {
            $this->addOrder($order);
        }

        return $this;
    }

    /**
     * @param Order $order
     */
    public function removeOrder(Order $order): void
    {
        $this->orders->removeElement($order);
    }

    /**
     * @param Collection $orders
     */
    public function removeOrders(Collection $orders): void
    {
        foreach ($orders as $order) {
            $this->removeOrder($order);
        }
    }

    public function getPriceRule(): PriceRule
    {
        return $this->priceRule;
    }

    public function addPriceRule(PriceRule $priceRule): void
    {
        $this->priceRule = $priceRule;
    }
}