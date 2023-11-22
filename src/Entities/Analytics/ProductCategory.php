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
#[ORM\HasLifecycleCallbacks]
class ProductCategory extends Entity
{
    #[ORM\OneToMany(mappedBy: 'productCategory', targetEntity: ChanneledProductCategory::class, orphanRemoval: true)]
    protected Collection $channeledProductCategories;

    public function __construct()
    {
        $this->channeledProductCategories = new ArrayCollection();
    }

    public function getChanneledProductCategories(): ?Collection
    {
        return $this->channeledProductCategories;
    }

    public function addChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if (!$this->channeledProductCategories->contains($channeledProductCategory)) {
            $this->channeledProductCategories->add($channeledProductCategory);
            $channeledProductCategory->addProductCategory($this);
        }

        return $this;
    }

    public function addChanneledProductCategories(Collection $channeledProductCategories): self
    {
        foreach ($channeledProductCategories as $channeledProductCategory) {
            $this->addChanneledProductCategory($channeledProductCategory);
        }

        return $this;
    }

    public function removeChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if ($this->channeledProductCategories->contains($channeledProductCategory)) {
            $this->channeledProductCategories->removeElement($channeledProductCategory);
            if ($channeledProductCategory->getProductCategory() === $this) {
                $channeledProductCategory->addProductCategory(null);
            }
        }

        return $this;
    }

    public function removeChanneledProductCategories(Collection $channeledProductCategories): void
    {
        foreach ($channeledProductCategories as $channeledProductCategory) {
            $this->removeChanneledProductCategory($channeledProductCategory);
        }
    }
}