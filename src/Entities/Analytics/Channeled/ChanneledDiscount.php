<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Discount;
use Repositories\Channeled\ChanneledDiscountRepository;

#[ORM\Entity(repositoryClass: ChanneledDiscountRepository::class)]
#[ORM\Table(name: 'channeled_discounts')]
#[ORM\Index(columns: ['code', 'platformId', 'channel'], name: 'code_platformId_channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['code', 'channel'], name: 'email_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\Index(columns: ['code'], name: 'code_idx')]
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
     * @return ChanneledDiscount
     */
    public function addCode(string $code): self
    {
        $this->code = $code;

        return $this;
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
        if ($this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->add($channeledOrder);

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
        if (!$this->channeledOrders->contains($channeledOrder)) {
            return $this;
        }

        $this->channeledOrders->removeElement($channeledOrder);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return ChanneledDiscount
     */
    public function removeChanneledOrders(Collection $channeledOrders): self
    {
        foreach ($channeledOrders as $channeledOrder) {
            $this->removeChanneledOrder($channeledOrder);
        }

        return $this;
    }

    /**
     * @return ChanneledPriceRule
     */
    public function getChanneledPriceRule(): ChanneledPriceRule
    {
        return $this->channeledPriceRule;
    }

    /**
     * @param ChanneledPriceRule|null $channeledPriceRule
     * @return ChanneledDiscount
     */
    public function addChanneledPriceRule(?ChanneledPriceRule $channeledPriceRule): self
    {
        $this->channeledPriceRule = $channeledPriceRule;

        return $this;
    }

    /**
     * @return Discount
     */
    public function getDiscount(): Discount
    {
        return $this->discount;
    }

    /**
     * @param Discount|null $discount
     * @return ChanneledDiscount
     */
    public function addDiscount(?Discount $discount): self
    {
        $this->discount = $discount;

        return $this;
    }
}