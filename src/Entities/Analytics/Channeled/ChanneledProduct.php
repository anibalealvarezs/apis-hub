<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Product;
use Repositories\Channeled\ChanneledProductRepository;

#[ORM\Entity(repositoryClass: ChanneledProductRepository::class)]
#[ORM\Table(name: 'channeled_products')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledProduct extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected int|string $platformId;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledProduct', targetEntity: 'ChanneledProductVariant', orphanRemoval: true)]
    protected Collection $channeledProductVariants;

    #[ORM\ManyToMany(targetEntity: 'ChanneledProductCategory', mappedBy: 'channeledProducts')]
    protected Collection $channeledProductCategories;

    #[ORM\ManyToMany(targetEntity: 'ChanneledOrder', mappedBy: 'channeledProducts')]
    protected Collection $channeledOrders;

    #[ORM\ManyToOne(targetEntity:"ChanneledVendor", inversedBy: 'channeledProducts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ChanneledVendor $channeledVendor;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'channeledProducts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Product $product;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->channeledProductVariants = new ArrayCollection();
        $this->channeledProductCategories = new ArrayCollection();
        $this->channeledOrders = new ArrayCollection();
    }

    public function getChanneledProductVariants(): ?Collection
    {
        return $this->channeledProductVariants;
    }

    public function addChanneledProductVariant(ChanneledProductVariant $channeledProductVariant): self
    {
        if ($this->channeledProductVariants->contains($channeledProductVariant)) {
            return $this;
        }

        $this->channeledProductVariants->add($channeledProductVariant);
        $channeledProductVariant->addChanneledProduct($this);

        return $this;
    }

    public function addChanneledProductVariants(Collection $channeledProductVariants): self
    {
        foreach ($channeledProductVariants as $channeledProductVariant) {
            $this->addChanneledProductVariant($channeledProductVariant);
        }

        return $this;
    }

    public function removeChanneledProductVariant(ChanneledProductVariant $channeledProductVariant): self
    {
        if (!$this->channeledProductVariants->contains($channeledProductVariant)) {
            return $this;
        }

        $this->channeledProductVariants->removeElement($channeledProductVariant);

        if ($channeledProductVariant->getChanneledProduct() !== $this) {
            return $this;
        }

        $channeledProductVariant->addChanneledProduct(channeledProduct: null);

        return $this;
    }

    public function removeChanneledProductVariants(Collection $channeledProductVariants): self
    {
        foreach ($channeledProductVariants as $channeledProductVariant) {
            $this->removeChanneledProductVariant($channeledProductVariant);
        }

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
     * @return ChanneledProduct
     */
    public function addChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if ($this->channeledProductCategories->contains($channeledProductCategory)) {
            return $this;
        }

        $this->channeledProductCategories->add($channeledProductCategory);

        return $this;
    }

    /**
     * @param Collection $channeledProductCategories
     * @return ChanneledProduct
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
     * @return ChanneledProduct
     */
    public function removeChanneledProductCategory(ChanneledProductCategory $channeledProductCategory): self
    {
        if (!$this->channeledProductCategories->contains($channeledProductCategory)) {
            return $this;
        }

        $this->channeledProductCategories->removeElement($channeledProductCategory);

        return $this;
    }

    /**
     * @param Collection $channeledProductCategories
     * @return ChanneledProduct
     */
    public function removeChanneledProductCategories(Collection $channeledProductCategories): self
    {
        foreach ($channeledProductCategories as $channeledProductCategory) {
            $this->removeChanneledProductCategory($channeledProductCategory);
        }

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return ChanneledProduct
     */
    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->add($channeledOrder);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return ChanneledProduct
     */
    public function addChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->addChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return ChanneledProduct
     */
    public function removeChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if (!$this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->removeElement($channeledOrder);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return ChanneledProduct
     */
    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    public function getChanneledVendor(): ChanneledVendor
    {
        return $this->channeledVendor;
    }

    public function addChanneledVendor(?ChanneledVendor $channeledVendor): self
    {
        $this->channeledVendor = $channeledVendor;

        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function addProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }
}