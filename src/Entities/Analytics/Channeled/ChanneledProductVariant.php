<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\ProductVariant;
use Repositories\Channeled\ChanneledProductVariantRepository;

#[ORM\Entity(repositoryClass: ChanneledProductVariantRepository::class)]
#[ORM\Table(name: 'channeled_product_variants')]
#[ORM\Index(columns: ['platform_id', 'channel'], name: 'idx_channeled_product_variants_platform_id_channel_idx')]
#[ORM\Index(columns: ['platform_id'], name: 'idx_channeled_product_variants_platform_id_idx')]
#[ORM\Index(columns: ['platform_created_at'], name: 'idx_channeled_product_variants_platform_created_at_idx')]
#[ORM\UniqueConstraint(name: 'channeled_product_variants_full_unique', columns: ['platform_id', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledProductVariant extends ChanneledEntity
{
    #[ORM\Column(name: 'platform_id', type: 'string')]
    protected int|string $platformId;

    #[ORM\Column(type: 'string', nullable: true)]
    protected int|string|null $sku;

    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: ChanneledOrder::class, mappedBy: 'channeledProductVariants')]
    protected Collection $channeledOrders;

    #[ORM\ManyToOne(targetEntity:ChanneledProduct::class, inversedBy: 'channeledProductVariants')]
    #[ORM\JoinColumn(name: 'channeled_product_id', onDelete: 'cascade')]
    protected ChanneledProduct $channeledProduct;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'channeledProductVariants')]
    #[ORM\JoinColumn(name: 'product_variant_id', onDelete: 'cascade')]
    protected ProductVariant $productVariant;

    /**
     * @return void
     */
    public function __construct()
    {
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
     * @return ChanneledProductVariant
     */
    public function addSku(?string $sku): self
    {
        $this->sku = $sku;

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
     * @return ChanneledProductVariant
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
     * @return ChanneledProductVariant
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
     * @return ChanneledProductVariant
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
     * @return ChanneledProductVariant
     */
    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @return ChanneledProduct
     */
    public function getChanneledProduct(): ChanneledProduct
    {
        return $this->channeledProduct;
    }

    /**
     * @param ChanneledProduct|null $channeledProduct
     * @return ChanneledProductVariant
     */
    public function addChanneledProduct(?ChanneledProduct $channeledProduct): self
    {
        $this->channeledProduct = $channeledProduct;

        return $this;
    }

    /**
     * @return ProductVariant
     */
    public function getProductVariant(): ProductVariant
    {
        return $this->productVariant;
    }

    /**
     * @param ProductVariant|null $productVariant
     * @return ChanneledProductVariant
     */
    public function addProductVariant(?ProductVariant $productVariant): self
    {
        $this->productVariant = $productVariant;

        return $this;
    }
}
