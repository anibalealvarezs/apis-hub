<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\PriceRuleRepository;

#[ORM\Entity(repositoryClass: PriceRuleRepository::class)]
#[ORM\Table(name: 'price_rules')]
#[ORM\HasLifecycleCallbacks]
class PriceRule extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    #[ORM\ManyToMany(targetEntity: 'Order', mappedBy: 'priceRules')]
    protected Collection $orders;

    #[ORM\OneToMany(mappedBy: 'priceRule', targetEntity: 'Discount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $discounts;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
        $this->discounts = new ArrayCollection();
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
     * @return PriceRule
     */
    public function addOrder(Order $order): self
    {
        $this->orders->add($order);

        return $this;
    }

    /**
     * @param Collection $orders
     * @return PriceRule
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

    public function getDiscounts(): ?Collection
    {
        return $this->discounts;
    }

    public function addDiscount(Discount $discount): self
    {
        $this->discounts->add($discount);

        return $this;
    }

    public function addDiscounts(Collection $discounts): self
    {
        foreach ($discounts as $discount) {
            $this->addDiscount($discount);
        }

        return $this;
    }

    public function removeDiscount(Discount $discount): void
    {
        $this->discounts->removeElement($discount);
    }

    public function removeDiscounts(Collection $discounts): void
    {
        foreach ($discounts as $discount) {
            $this->removeDiscount($discount);
        }
    }
}