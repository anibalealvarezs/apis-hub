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
#[ORM\HasLifecycleCallbacks]
class Product extends Entity
{
    #[ORM\Column(type: 'bigint', unique: true)]
    protected int|string $productId;

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
     */
    public function addProductId(string $productId): self
    {
        $this->productId = $productId;

        return $this;
    }

    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->add($channeledProduct);
            $channeledProduct->addProduct($this);
        }

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
        if ($this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->removeElement($channeledProduct);
            if ($channeledProduct->getProduct() === $this) {
                $channeledProduct->addProduct(null);
            }
        }

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