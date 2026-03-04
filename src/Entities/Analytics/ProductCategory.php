<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledProductCategory;
use Entities\Entity;
use Repositories\ProductCategoryRepository;

#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\Table(name: 'productCategories')]
#[ORM\Index(columns: ['productCategoryId'], name: 'productCategoryId_idx')]
#[ORM\HasLifecycleCallbacks]
class ProductCategory extends Entity
{
    #[ORM\Column(type: 'bigint', unique: true)]
    protected int|string $productCategoryId;

    #[ORM\Column(type: 'boolean')]
    protected bool $isSmartCollection;

    #[ORM\OneToMany(mappedBy: 'productCategory', targetEntity: ChanneledProductCategory::class, orphanRemoval: true)]
    protected Collection $channeledProductCategories;

    public function __construct()
    {
        $this->channeledProductCategories = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getProductCategoryId(): string
    {
        return $this->productCategoryId;
    }

    /**
     * @param string $productCategoryId
     * @return ProductCategory
     */
    public function addProductCategoryId(string $productCategoryId): self
    {
        $this->productCategoryId = $productCategoryId;

        return $this;
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
     * @return ProductCategory
     */
    public function addIsSmartCollection(bool $isSmartCollection): self
    {
        $this->isSmartCollection = $isSmartCollection;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledProductCategories(): ?Collection
    {
        return $this->channeledProductCategories;
    }

    /**
     * @param ChanneledProductCategory $channeledProductCategory
     * @return ProductCategory
     */
    public function addChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if ($this->channeledProductCategories->contains($channeledProductCategory)) {
            return $this;
        }

        $this->channeledProductCategories->add($channeledProductCategory);
        $channeledProductCategory->addProductCategory($this);

        return $this;
    }

    /**
     * @param Collection $channeledProductCategories
     * @return ProductCategory
     */
    public function addChanneledProductCategories(Collection $channeledProductCategories): self
    {
        foreach ($channeledProductCategories as $channeledProductCategory) {
            $this->addChanneledProductCategory($channeledProductCategory);
        }

        return $this;
    }

    /**
     * @param ChanneledProductCategory $channeledProductCategory
     * @return ProductCategory
     */
    public function removeChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if (!$this->channeledProductCategories->contains($channeledProductCategory)) {
            return $this;
        }

        $this->channeledProductCategories->removeElement($channeledProductCategory);

        if ($channeledProductCategory->getProductCategory() !== $this) {
            return $this;
        }

        $channeledProductCategory->addProductCategory(productCategory: null);

        return $this;
    }

    /**
     * @param Collection $channeledProductCategories
     * @return ProductCategory
     */
    public function removeChanneledProductCategories(Collection $channeledProductCategories): self
    {
        foreach ($channeledProductCategories as $channeledProductCategory) {
            $this->removeChanneledProductCategory($channeledProductCategory);
        }

        return $this;
    }
}
