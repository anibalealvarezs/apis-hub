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
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ChanneledProduct::class, orphanRemoval: true)]
    protected Collection $channeledProducts;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
    }

    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        $this->channeledProducts->add($channeledProduct);

        return $this;
    }

    public function addChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function removeChanneledProduct(ChanneledProduct $channeledProduct): void
    {
        $this->channeledProducts->removeElement($channeledProduct);
    }

    public function removeChanneledProducts(Collection $channeledProducts): void
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }
    }
}