<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Order;
use Repositories\Channeled\ChanneledOrderRepository;

#[ORM\Entity(repositoryClass: ChanneledOrderRepository::class)]
#[ORM\Table(name: 'channeled_orders')]
#[ORM\Index(columns: ['platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledOrder extends ChanneledEntity
{
    // Relationships with channeled entities

    #[ORM\ManyToOne(targetEntity:"ChanneledCustomer", inversedBy: 'channeledOrders')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ChanneledCustomer $channeledCustomer;

    #[ORM\ManyToMany(targetEntity: 'ChanneledProduct', inversedBy: 'channeledOrders')]
    #[ORM\JoinTable(name: 'channeled_order_channeled_products')]
    protected Collection $channeledProducts;

    #[ORM\ManyToMany(targetEntity: 'ChanneledDiscount', inversedBy: 'channeledOrders')]
    #[ORM\JoinTable(name: 'channeled_order_channeled_discounts')]
    protected Collection $channeledDiscounts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'channeledOrders')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Order $order;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
        $this->channeledDiscounts = new ArrayCollection();
    }

    /**
     * @return ChanneledCustomer
     */
    public function getChanneledCustomer(): ChanneledCustomer
    {
        return $this->channeledCustomer;
    }

    /**
     * @param ChanneledCustomer|null $channeledCustomer
     * @return ChanneledOrder
     */
    public function addChanneledCustomer(?ChanneledCustomer $channeledCustomer): self
    {
        $this->channeledCustomer = $channeledCustomer;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    /**
     * @param ChanneledProduct $channeledProduct
     * @return ChanneledOrder
     */
    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->add($channeledProduct);
            $channeledProduct->addChanneledOrder($this);
        }

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledOrder
     */
    public function addChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    /**
     * @param ChanneledProduct $channeledProduct
     * @return ChanneledOrder
     */
    public function removeChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->removeElement($channeledProduct);
            if ($channeledProduct->getChanneledOrders()->contains($this)) {
                $channeledProduct->removeChanneledOrder($this);
            }
        }

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     */
    public function removeChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function getChanneledDiscounts(): ?Collection
    {
        return $this->channeledDiscounts;
    }

    public function addChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        if (!$this->channeledDiscounts->contains($channeledDiscount)) {
            $this->channeledDiscounts->add($channeledDiscount);
            $channeledDiscount->addChanneledOrder($this);
        }

        return $this;
    }

    public function addChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->addChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    public function removeChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        if ($this->channeledDiscounts->contains($channeledDiscount)) {
            $this->channeledDiscounts->removeElement($channeledDiscount);
            if ($channeledDiscount->getChanneledOrders()->contains($this)) {
                $channeledDiscount->removeChanneledOrder($this);
            }
        }

        return $this;
    }

    public function removeDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->removeChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function addOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }
}