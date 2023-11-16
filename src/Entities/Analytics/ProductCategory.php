<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\ProductCategoryRepository;

#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\Table(name: 'product_categories')]
#[ORM\HasLifecycleCallbacks]
class ProductCategory extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    // Many Categories have Many Products.
    #[ORM\ManyToMany(targetEntity: 'Product', inversedBy: 'productCategories', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'product_categories_products')]
    protected ArrayCollection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
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
    public function getProducts(): ?Collection
    {
        return $this->products;
    }

    /**
     * @param Product $product
     * @return ProductCategory
     */
    public function addProduct(Product $product): self
    {
        $this->products->add($product);

        return $this;
    }

    /**
     * @param Collection $products
     * @return ProductCategory
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
}