<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\ProductCategory;
use Repositories\Channeled\ChanneledProductCategoryRepository;

#[ORM\Entity(repositoryClass: ChanneledProductCategoryRepository::class)]
#[ORM\Table(name: 'channeled_product_categories')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledProductCategory extends ChanneledEntity
{
    #[ORM\Column(type: 'boolean')]
    protected bool $isSmartCollection;

    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: ChanneledProduct::class, inversedBy: 'channeledProductCategories', cascade: ['persist'])]
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
     * @return static
     */
    public function addIsSmartCollection(bool $isSmartCollection): static
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
     * @return static
     */
    public function addChanneledProduct(ChanneledProduct $channeledProduct): static
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->add($channeledProduct);
        $channeledProduct->addChanneledProductCategory($this);

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return static
     */
    public function addChanneledProducts(Collection $channeledProducts): static
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    /**
     * @param ChanneledProduct $channeledProduct
     * @return static
     */
    public function removeChanneledProduct(ChanneledProduct $channeledProduct): static
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->removeElement($channeledProduct);

        if (!$channeledProduct->getChanneledProductCategories()->contains($this)) {
            return $this;
        }

        $channeledProduct->removeChanneledProductCategory($this);

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return static
     */
    public function removeChanneledProducts(Collection $channeledProducts): static
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }

    /**
     * @return ProductCategory
     */
    public function getProductCategory(): ProductCategory
    {
        return $this->productCategory;
    }

    /**
     * @param ProductCategory|null $productCategory
     * @return static
     */
    public function addProductCategory(?ProductCategory $productCategory): static
    {
        $this->productCategory = $productCategory;

        return $this;
    }
}