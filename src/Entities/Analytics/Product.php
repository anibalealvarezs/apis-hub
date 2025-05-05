<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Entity;
use Repositories\ProductRepository;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\Index(columns: ['productId'], name: 'productId_idx')]
#[ORM\Index(columns: ['sku'], name: 'sku_idx')]
#[ORM\Index(columns: ['productId', 'sku'], name: 'productId_sku_idx')]
#[ORM\HasLifecycleCallbacks]
class Product extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected int|string $productId;

    #[ORM\Column(type: 'string', nullable: true)]
    protected int|string $sku;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ChanneledProduct::class, orphanRemoval: true)]
    protected Collection $channeledProducts;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getProductId(): string
    {
        return $this->productId;
    }

    /**
     * @param string $productId
     * @return Product
     */
    public function addProductId(string $productId): self
    {
        $this->productId = $productId;

        return $this;
    }

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @param string $sku
     * @return Product
     */
    public function addSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->add($channeledProduct);
        $channeledProduct->addProduct($this);

        return $this;
    }

    public function addChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function removeChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->removeElement($channeledProduct);

        if ($channeledProduct->getProduct() !== $this) {
            return $this;
        }

        $channeledProduct->addProduct(product: null);

        return $this;
    }

    public function removeChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }
}