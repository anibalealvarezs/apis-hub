<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledVendor;
use Entities\Entity;
use Repositories\VendorRepository;

#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendors')]
#[ORM\HasLifecycleCallbacks]
class Vendor extends Entity
{
    #[ORM\OneToMany(mappedBy: 'vendor', targetEntity: '\Entities\Analytics\Channeled\ChanneledVendor', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledVendors;

    public function __construct()
    {
        $this->channeledVendors = new ArrayCollection();
    }

    public function getChanneledVendors(): ?Collection
    {
        return $this->channeledVendors;
    }

    public function addChanneledVendor(ChanneledVendor $channeledVendor): self
    {
        $this->channeledVendors->add($channeledVendor);

        return $this;
    }

    public function addChanneledVendors(Collection $channeledVendors): self
    {
        foreach ($channeledVendors as $channeledVendor) {
            $this->addChanneledVendor($channeledVendor);
        }

        return $this;
    }

    public function removeChanneledVendor(ChanneledVendor $channeledVendor): void
    {
        $this->channeledVendors->removeElement($channeledVendor);
    }

    public function removeChanneledVendors(Collection $channeledVendors): void
    {
        foreach ($channeledVendors as $channeledVendor) {
            $this->removeChanneledVendor($channeledVendor);
        }
    }
}