<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Vendor;
use Repositories\Channeled\ChanneledVendorRepository;

#[ORM\Entity(repositoryClass: ChanneledVendorRepository::class)]
#[ORM\Table(name: 'channeled_vendors')]
#[ORM\Index(columns: ['name', 'platformId', 'channel'], name: 'idx_cv_full')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'idx_cv_platform_channel')]
#[ORM\Index(columns: ['name', 'channel'], name: 'idx_cv_name_channel')]
#[ORM\Index(columns: ['platformId'], name: 'idx_cv_platformId')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'idx_cv_createdAt')]
#[ORM\Index(columns: ['name'], name: 'idx_cv_name')]
#[ORM\HasLifecycleCallbacks]
class ChanneledVendor extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledVendor', targetEntity: ChanneledProduct::class, orphanRemoval: true)]
    protected Collection $channeledProducts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'channeledVendors')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Vendor $vendor;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ChanneledVendor
     */
    public function addName(string $name): self
    {
        $this->name = $name;

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
     * @return ChanneledVendor
     */
    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->add($channeledProduct);
        $channeledProduct->addChanneledVendor($this);

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledVendor
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
     * @return ChanneledVendor
     */
    public function removeChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            return $this;
        }

        $this->channeledProducts->removeElement($channeledProduct);

        if ($channeledProduct->getChanneledVendor() !== $this) {
            return $this;
        }

        $channeledProduct->addChanneledVendor(channeledVendor: null);

        return $this;
    }

    /**
     * @param Collection $channeledProducts
     * @return ChanneledVendor
     */
    public function removeChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }

    /**
     * @return Vendor
     */
    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    /**
     * @param Vendor|null $vendor
     * @return ChanneledVendor
     */
    public function addVendor(?Vendor $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }
}
