<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\ProductVariant;
use Repositories\Channeled\ChanneledProductVariantRepository;

#[ORM\Entity(repositoryClass: ChanneledProductVariantRepository::class)]
#[ORM\Table(name: 'channeled_product_variants')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledProductVariant extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected int|string $platformId;

    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: 'ChanneledOrder', mappedBy: 'channeledProductVariants')]
    protected Collection $channeledOrders;

    #[ORM\ManyToOne(targetEntity:"ChanneledProduct", inversedBy: 'channeledProductVariants')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ChanneledProduct $channeledProduct;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'channeledProductVariants')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ProductVariant $productVariant;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
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

    public function getChanneledProduct(): ChanneledProduct
    {
        return $this->channeledProduct;
    }

    public function addChanneledProduct(?ChanneledProduct $channeledProduct): self
    {
        $this->channeledProduct = $channeledProduct;

        return $this;
    }

    public function getProductVariant(): ProductVariant
    {
        return $this->productVariant;
    }

    public function addProductVariant(?ProductVariant $productVariant): self
    {
        $this->productVariant = $productVariant;

        return $this;
    }
}