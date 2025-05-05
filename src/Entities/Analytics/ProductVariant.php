<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledProduct;
use Entities\Analytics\Channeled\ChanneledProductVariant;
use Entities\Entity;
use Repositories\ProductVariantRepository;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variants')]
#[ORM\Index(columns: ['productVariantId'], name: 'productVariantId_idx')]
#[ORM\Index(columns: ['sku'], name: 'sku_idx')]
#[ORM\Index(columns: ['productVariantId', 'sku'], name: 'productVariantId_sku_idx')]
#[ORM\HasLifecycleCallbacks]
class ProductVariant extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected int|string $productVariantId;

    #[ORM\Column(type: 'string', nullable: true)]
    protected int|string $sku;

    #[ORM\OneToMany(mappedBy: 'productVariant', targetEntity: ChanneledProductVariant::class, orphanRemoval: true)]
    protected Collection $channeledProductVariants;

    public function __construct()
    {
        $this->channeledProductVariants = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getProductVariantId(): string
    {
        return $this->productVariantId;
    }

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @param string $sku
     * @return ProductVariant
     */
    public function addSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    /**
     * @param string $productVariantId
     * @return ProductVariant
     */
    public function addProductVariantId(string $productVariantId): self
    {
        $this->productVariantId = $productVariantId;

        return $this;
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
        $channeledProductVariant->addProductVariant($this);

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

        if ($channeledProductVariant->getProductVariant() !== $this) {
            return $this;
        }

        $channeledProductVariant->addProductVariant(productVariant: null);

        return $this;
    }

    public function removeChanneledProductVariants(Collection $channeledProductVariants): self
    {
        foreach ($channeledProductVariants as $channeledProductVariant) {
            $this->removeChanneledProductVariant($channeledProductVariant);
        }

        return $this;
    }
}