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

    #[ORM\ManyToMany(targetEntity: 'ChanneledProduct', inversedBy: 'channeledOrders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'channeled_order_channeled_products')]
    protected ArrayCollection $channeledProducts;

    #[ORM\ManyToMany(targetEntity: 'ChanneledPriceRule', inversedBy: 'channeledOrders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'channeled_order_channeled_price_rules')]
    protected ArrayCollection $channeledPriceRules;

    #[ORM\ManyToMany(targetEntity: 'ChanneledDiscount', inversedBy: 'channeledOrders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'channeled_order_channeled_discounts')]
    protected ArrayCollection $channeledDiscounts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'channeledOrders')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Order $order;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
        $this->channeledPriceRules = new ArrayCollection();
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
     * @param ChanneledCustomer $channeledCustomer
     */
    public function addChanneledCustomer(ChanneledCustomer $channeledCustomer): void
    {
        $this->channeledCustomer = $channeledCustomer;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    /**
     * @param ChanneledProduct $channeledProducts
     * @return ChanneledOrder
     */
    public function addChanneledProduct(ChanneledProduct $channeledProducts): self
    {
        $this->channeledProducts->add($channeledProducts);

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledOrder
     */
    public function addProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    /**
     * @param ChanneledProduct $channeledProduct
     */
    public function removeChanneledProduct(ChanneledProduct $channeledProduct): void
    {
        $this->channeledProducts->removeElement($channeledProduct);
    }

    /**
     * @param Collection $channeledProducts
     */
    public function removeChanneledProducts(Collection $channeledProducts): void
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }
    }

    public function getChanneledDiscounts(): ?Collection
    {
        return $this->channeledDiscounts;
    }

    public function addChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        $this->channeledDiscounts->add($channeledDiscount);

        return $this;
    }

    public function addChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->addChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    public function removeChanneledDiscount(ChanneledDiscount $channeledDiscount): void
    {
        $this->channeledDiscounts->removeElement($channeledDiscount);
    }

    public function removeDiscounts(Collection $channeledDiscounts): void
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->removeChanneledDiscount($channeledDiscount);
        }
    }

    public function getChanneledPriceRules(): ?Collection
    {
        return $this->channeledPriceRules;
    }

    public function addChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): self
    {
        $this->channeledPriceRules->add($channeledPriceRule);

        return $this;
    }

    public function addChanneledPriceRules(Collection $channeledPriceRules): self
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->addChanneledPriceRule($channeledPriceRule);
        }

        return $this;
    }

    public function removeChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): void
    {
        $this->channeledPriceRules->removeElement($channeledPriceRule);
    }

    public function removeChanneledPriceRules(Collection $channeledPriceRules): void
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->removeChanneledPriceRule($channeledPriceRule);
        }
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function addOrder(Order $order): void
    {
        $this->order = $order;
    }
}