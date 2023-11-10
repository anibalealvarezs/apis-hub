<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\ProductRepository;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\HasLifecycleCallbacks]
class Product extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    // Many Products have Many ProductCategories.
    #[ORM\ManyToMany(targetEntity: 'ProductCategory', mappedBy: 'products')]
    protected Collection $productCategories;

    // Many Products have Many Orders.
    #[ORM\ManyToMany(targetEntity: 'Order', mappedBy: 'products')]
    protected Collection $orders;

    #[ORM\ManyToOne(targetEntity:"Vendor", cascade: ['persist'], inversedBy: 'products')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Vendor $vendor;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->productCategories = new ArrayCollection();
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
    public function getProductCategories(): ?Collection
    {
        return $this->productCategories;
    }

    /**
     * @param ProductCategory $productCategory
     * @return Product
     */
    public function addProductCategory(ProductCategory $productCategory): self
    {
        $this->productCategories->add($productCategory);

        return $this;
    }

    /**
     * @param Collection $productCategories
     * @return Product
     */
    public function addProductCategories(Collection $productCategories): self
    {
        foreach ($productCategories as $productCategory) {
            $this->addProductCategory($productCategory);
        }

        return $this;
    }

    /**
     * @param ProductCategory $productCategory
     */
    public function removeProductCategory(ProductCategory $productCategory): void
    {
        $this->productCategories->removeElement($productCategory);
    }

    /**
     * @param Collection $productCategories
     */
    public function removeProductCategories(Collection $productCategories): void
    {
        foreach ($productCategories as $productCategory) {
            $this->removeProductCategory($productCategory);
        }
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
     * @return Product
     */
    public function addOrder(Order $order): self
    {
        $this->orders->add($order);

        return $this;
    }

    /**
     * @param Collection $orders
     * @return Product
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

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function addVendor(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }
}