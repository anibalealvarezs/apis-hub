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
#[ORM\Index(columns: ['name'], name: 'name_idx')]
#[ORM\HasLifecycleCallbacks]
class Vendor extends Entity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'vendor', targetEntity: ChanneledVendor::class, orphanRemoval: true)]
    protected Collection $channeledVendors;

    public function __construct()
    {
        $this->channeledVendors = new ArrayCollection();
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
     * @return Vendor
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getChanneledVendors(): ?Collection
    {
        return $this->channeledVendors;
    }

    public function addChanneledVendor(ChanneledVendor $channeledVendor): self
    {
        if ($this->channeledVendors->contains($channeledVendor)) {
            return $this;
        }

        $this->channeledVendors->add($channeledVendor);
        $channeledVendor->addVendor($this);

        return $this;
    }

    public function addChanneledVendors(Collection $channeledVendors): self
    {
        foreach ($channeledVendors as $channeledVendor) {
            $this->addChanneledVendor($channeledVendor);
        }

        return $this;
    }

    public function removeChanneledVendor(ChanneledVendor $channeledVendor): self
    {
        if (!$this->channeledVendors->contains($channeledVendor)) {
            return $this;
        }

        $this->channeledVendors->removeElement($channeledVendor);

        if ($channeledVendor->getVendor() !== $this) {
            return $this;
        }

        $channeledVendor->addVendor(vendor: null);

        return $this;
    }

    public function removeChanneledVendors(Collection $channeledVendors): self
    {
        foreach ($channeledVendors as $channeledVendor) {
            $this->removeChanneledVendor($channeledVendor);
        }

        return $this;
    }
}