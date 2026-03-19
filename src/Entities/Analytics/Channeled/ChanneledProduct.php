<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Product;
use Entities\Analytics\Vendor;
use Repositories\Channeled\ChanneledProductRepository;

#[ORM\Entity(repositoryClass: ChanneledProductRepository::class)]
#[ORM\Table(name: 'channeled_products')]
#[ORM\Index(columns: ['platform_id', 'channel'], name: 'idx_channeled_products_platform_id_channel_idx')]
#[ORM\Index(columns: ['platform_id'], name: 'idx_channeled_products_platform_id_idx')]
#[ORM\Index(columns: ['sku'], name: 'idx_channeled_products_sku_idx')]
#[ORM\Index(columns: ['platform_created_at'], name: 'idx_channeled_products_platform_created_at_idx')]
#[ORM\Index(columns: ['platform_id', 'sku'], name: 'idx_channeled_products_pid_sku_idx')]
#[ORM\Index(columns: ['platform_id', 'platform_created_at'], name: 'idx_channeled_products_pid_created_idx')]
#[ORM\Index(columns: ['sku', 'platform_created_at'], name: 'idx_channeled_products_sku_created_idx')]
#[ORM\Index(columns: ['platform_id', 'sku', 'platform_created_at'], name: 'idx_channeled_products_full_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledProduct extends ChanneledEntity
{
    #[ORM\Column(name: 'platform_id', type: 'string')]
    protected int|string $platformId;

    #[ORM\Column(type: 'string', nullable: true)]
    protected int|string|null $sku;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledProduct', targetEntity: ChanneledProductVariant::class, orphanRemoval: true)]
    protected Collection $channeledProductVariants;

    #[ORM\ManyToMany(targetEntity: ChanneledProductCategory::class, mappedBy: 'channeledProducts')]
    protected Collection $channeledProductCategories;

    #[ORM\ManyToMany(targetEntity: ChanneledOrder::class, mappedBy: 'channeledProducts')]
    protected Collection $channeledOrders;

    #[ORM\ManyToOne(targetEntity: ChanneledVendor::class, inversedBy: 'channeledProducts')]
    #[ORM\JoinColumn(name: 'channeled_vendor_id', onDelete: 'CASCADE')]
    protected ChanneledVendor $channeledVendor;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'channeledProducts')]
    #[ORM\JoinColumn(name: 'product_id', onDelete: 'CASCADE')]
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

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @param string|null $sku
     * @return ChanneledProduct
     */
    public function addSku(?string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledProductVariants(): ?Collection
    {
        return $this->channeledProductVariants;
    }

    /**
     * @param ChanneledProductVariant $channeledProductVariant
     * @return ChanneledProduct
     */
    public function addChanneledProductVariant(ChanneledProductVariant $channeledProductVariant): self
    {
        if ($this->channeledProductVariants->contains($channeledProductVariant)) {
            return $this;
        }

        $this->channeledProductVariants->add($channeledProductVariant);
        $channeledProductVariant->addChanneledProduct($this);

        return $this;
    }

    /**
     * @param Collection $channeledProductVariants
     * @return ChanneledProduct
     */
    public function addChanneledProductVariants(Collection $channeledProductVariants): self
    {
        foreach ($channeledProductVariants as $channeledProductVariant) {
            $this->addChanneledProductVariant($channeledProductVariant);
        }

        return $this;
    }

    /**
     * @param ChanneledProductVariant $channeledProductVariant
     * @return ChanneledProduct
     */
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

    /**
     * @param Collection $channeledProductVariants
     * @return ChanneledProduct
     */
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

    /**
     * @return ChanneledVendor
     */
    public function getChanneledVendor(): ChanneledVendor
    {
        return $this->channeledVendor;
    }

    /**
     * @param ChanneledVendor|null $channeledVendor
     * @return ChanneledProduct
     */
    public function addChanneledVendor(?ChanneledVendor $channeledVendor): self
    {
        $this->channeledVendor = $channeledVendor;

        return $this;
    }

    /**
     * @return Product
     */
    public function getProduct(): Product
    {
        return $this->product;
    }

    /**
     * @param Product|null $product
     * @return ChanneledProduct
     */
    public function addProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }
}
