<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\ProductCategory;
use Repositories\Channeled\ChanneledProductCategoryRepository;

#[ORM\Entity(repositoryClass: ChanneledProductCategoryRepository::class)]
#[ORM\Table(name: 'channeled_product_categories')]
#[ORM\Index(columns: ['platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledProductCategory extends ChanneledEntity
{
    #[ORM\Column(type: 'boolean')]
    protected bool $isSmartCollection;

    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: 'ChanneledProduct', inversedBy: 'channeledProductCategories', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'channeled_product_categories_channeled_products')]
    protected Collection $channeledProducts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: ProductCategory::class, inversedBy: 'channeledProductCategories')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ProductCategory $productCategory;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
    }

    /**
     * @return bool
     */
    public function getIsSmartCollection(): bool
    {
        return $this->isSmartCollection;
    }

    /**
     * @param bool $isSmartCollection
     * @return ChanneledProductCategory
     */
    public function addIsSmartCollection(bool $isSmartCollection): self
    {
        $this->isSmartCollection = $isSmartCollection;

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
     * @return ChanneledProductCategory
     */
    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->add($channeledProduct);
            $channeledProduct->addChanneledProductCategory($this);
        }

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledProductCategory
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
     * @return ChanneledProductCategory
     */
    public function removeChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->removeElement($channeledProduct);
            if ($channeledProduct->getChanneledProductCategories()->contains($this)) {
                $channeledProduct->removeChanneledProductCategory($this);
            }
        }

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledProductCategory
     */
    public function removeChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function getProductCategory(): ProductCategory
    {
        return $this->productCategory;
    }

    public function addProductCategory(?ProductCategory $productCategory): self
    {
        $this->productCategory = $productCategory;

        return $this;
    }
}