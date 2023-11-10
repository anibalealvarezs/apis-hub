<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Interfaces\ChannelInterface;
use Repositories\OrderRepository;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    #[ORM\ManyToOne(targetEntity:"Customer", inversedBy: 'orders')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Customer $customer;

    // Many Categories have Many Products.
    #[ORM\ManyToMany(targetEntity: 'Product', inversedBy: 'orders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'order_products')]
    protected ArrayCollection $products;

    #[ORM\ManyToMany(targetEntity: 'PriceRule', inversedBy: 'orders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'order_price_rules')]
    protected ArrayCollection $priceRules;

    #[ORM\ManyToMany(targetEntity: 'Discount', inversedBy: 'orders', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'order_discounts')]
    protected ArrayCollection $discounts;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->priceRules = new ArrayCollection();
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
     * @return Customer
     */
    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * @param Customer $customer
     */
    public function addCustomer(Customer $customer): void
    {
        $this->customer = $customer;
    }

    /**
     * @return Collection|null
     */
    public function getProducts(): ?Collection
    {
        return $this->products;
    }

    /**
     * @param Product $product
     * @return Order
     */
    public function addProduct(Product $product): self
    {
        $this->products->add($product);

        return $this;
    }

    /**
     * @param Collection $products
     * @return Order
     */
    public function addProducts(Collection $products): self
    {
        foreach ($products as $product) {
            $this->addProduct($product);
        }

        return $this;
    }

    /**
     * @param Product $product
     */
    public function removeProduct(Product $product): void
    {
        $this->products->removeElement($product);
    }

    /**
     * @param Collection $products
     */
    public function removeProducts(Collection $products): void
    {
        foreach ($products as $product) {
            $this->removeProduct($product);
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

    public function getPriceRules(): ?Collection
    {
        return $this->priceRules;
    }

    public function addPriceRule(PriceRule $priceRule): self
    {
        $this->priceRules->add($priceRule);

        return $this;
    }

    public function addPriceRules(Collection $priceRules): self
    {
        foreach ($priceRules as $priceRule) {
            $this->addDiscount($priceRule);
        }

        return $this;
    }

    public function removePriceRule(PriceRule $priceRule): void
    {
        $this->priceRules->removeElement($priceRule);
    }

    public function removePriceRules(Collection $priceRules): void
    {
        foreach ($priceRules as $priceRule) {
            $this->removeDiscount($priceRule);
        }
    }
}