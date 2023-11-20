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
#[ORM\Index(columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class Discount extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $code;

    #[ORM\OneToMany(mappedBy: 'discount', targetEntity: ChanneledDiscount::class, orphanRemoval: true)]
    protected Collection $channeledDiscounts;

    public function __construct()
    {
        $this->channeledDiscounts = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $code
     */
    public function addCode(string $code): void
    {
        $this->code = $code;
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