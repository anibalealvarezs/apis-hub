<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Vendor;
use Repositories\Channeled\ChanneledVendorRepository;

#[ORM\Entity(repositoryClass: ChanneledVendorRepository::class)]
#[ORM\Table(name: 'channeled_vendors')]
#[ORM\Index(columns: ['name', 'platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledVendor extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledVendor', targetEntity: 'ChanneledProduct', orphanRemoval: true)]
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

    public function getChanneledProducts(): ?Collection
    {
        return $this->channeledProducts;
    }

    public function addChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if (!$this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->add($channeledProduct);
            $channeledProduct->addChanneledVendor($this);
        }

        return $this;
    }

    public function addChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->addChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function removeChanneledProduct(ChanneledProduct $channeledProduct): self
    {
        if ($this->channeledProducts->contains($channeledProduct)) {
            $this->channeledProducts->removeElement($channeledProduct);
            if ($channeledProduct->getChanneledVendor() === $this) {
                $channeledProduct->addChanneledVendor(null);
            }
        }

        return $this;
    }

    public function removeChanneledProducts(Collection $channeledProducts): self
    {
        foreach ($channeledProducts as $channeledProduct) {
            $this->removeChanneledProduct($channeledProduct);
        }

        return $this;
    }

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function addVendor(?Vendor $vendor): self
    {
        $this->vendor = $vendor;

        return $this;
    }
}