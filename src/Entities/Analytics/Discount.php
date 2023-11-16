<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledDiscount;
use Entities\Entity;
use Repositories\DiscountRepository;

#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table(name: 'discounts')]
#[ORM\HasLifecycleCallbacks]
class Discount extends Entity
{
    #[ORM\OneToMany(mappedBy: 'discount', targetEntity: '\Entities\Analytics\Channeled\ChanneledDiscount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledDiscounts;

    public function __construct()
    {
        $this->channeledDiscounts = new ArrayCollection();
    }

    public function getChanneledDiscounts(): ?Collection
    {
        return $this->channeledDiscounts;
    }

    public function addChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        $this->channeledDiscounts->add($channeledDiscount);

        return $this;
    }

    public function addChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->addChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    public function removeChanneledDiscount(ChanneledDiscount $channeledDiscount): void
    {
        $this->channeledDiscounts->removeElement($channeledDiscount);
    }

    public function removeChanneledDiscounts(Collection $channeledDiscounts): void
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->removeChanneledDiscount($channeledDiscount);
        }
    }
}