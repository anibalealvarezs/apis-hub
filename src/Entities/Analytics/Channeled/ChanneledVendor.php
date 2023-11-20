<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Vendor;
use Repositories\Channeled\ChanneledVendorRepository;

#[ORM\Entity(repositoryClass: ChanneledVendorRepository::class)]
#[ORM\Table(name: 'channeled_vendors')]
#[ORM\Index(columns: ['platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledVendor extends ChanneledEntity
{
    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledVendor', targetEntity: 'ChanneledProduct', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledProducts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Vendor::class, inversedBy: 'channeledVendors')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Vendor $vendor;

    public function __construct()
    {
        $this->channeledProducts = new ArrayCollection();
    }

    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        $this->channeledProducts->add($channeledProduct);

        return $this;
    }

    public function addChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function removeChanneledProduct(ChanneledProduct $channeledProduct): void
    {
        $this->channeledProducts->removeElement($channeledProduct);
    }

    public function removeChanneledProducts(Collection $channeledProducts): void
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }
    }

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function addVendor(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }
}