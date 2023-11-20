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
    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: 'ChanneledProduct', inversedBy: 'channeledProductCategories', cascade: ['persist'])]
    #[ORM\JoinTable(name: 'channeled_product_categories_channeled_products')]
    protected ArrayCollection $channeledProducts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: ProductCategory::class, inversedBy: 'channeledProductCategories')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ProductCategory $productCategory;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
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
        $this->channeledProducts->add($channeledProduct);

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

    public function getProductCategory(): ProductCategory
    {
        return $this->productCategory;
    }

    public function addProductCategory(ProductCategory $productCategory): void
    {
        $this->productCategory = $productCategory;
    }
}