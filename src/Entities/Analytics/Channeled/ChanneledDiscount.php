<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Discount;
use Repositories\Channeled\ChanneledDiscountRepository;

#[ORM\Entity(repositoryClass: ChanneledDiscountRepository::class)]
#[ORM\Table(name: 'channeled_discounts')]
#[ORM\Index(columns: ['code', 'platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledDiscount extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $code;

    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: 'ChanneledOrder', mappedBy: 'channeledDiscounts')]
    protected Collection $channeledOrders;

    #[ORM\ManyToOne(targetEntity: "ChanneledPriceRule", inversedBy: 'channeledDiscounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected ChanneledPriceRule $channeledPriceRule;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Discount::class, inversedBy: 'channeledDiscounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Discount $discount;

    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
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

    /**
     * @return Collection|null
     */
    public function getChanneledOrders(): ?Collection
    {
        return $this->channeledOrders;
    }

    /**
     * @param ChanneledOrder $channeledOrder
     * @return ChanneledDiscount
     */
    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if (!$this->channeledOrders->contains($channeledOrder)) {
            $this->channeledOrders->add($channeledOrder);
        }

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return ChanneledDiscount
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
     * @return ChanneledDiscount
     */
    public function removeChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        if ($this->channeledOrders->contains($channeledOrder)) {
            $this->channeledOrders->removeElement($channeledOrder);
        }

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     */
    public function removeChanneledOrders(Collection $channeledOrders): void
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }
    }

    public function getChanneledPriceRule(): ChanneledPriceRule
    {
        return $this->channeledPriceRule;
    }

    public function addChanneledPriceRule(?ChanneledPriceRule $channeledPriceRule): self
    {
        $this->channeledPriceRule = $channeledPriceRule;

        return $this;
    }

    public function getDiscount(): Discount
    {
        return $this->discount;
    }

    public function addDiscount(?Discount $discount): self
    {
        $this->discount = $discount;

        return $this;
    }
}