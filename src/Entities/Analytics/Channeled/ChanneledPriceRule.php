<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\PriceRule;
use Repositories\Channeled\ChanneledPriceRuleRepository;

#[ORM\Entity(repositoryClass: ChanneledPriceRuleRepository::class)]
#[ORM\Table(name: 'channeled_price_rules')]
#[ORM\Index(columns: ['platformId', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class ChanneledPriceRule extends ChanneledEntity
{
    // Relationships with channeled entities

    #[ORM\ManyToMany(targetEntity: 'ChanneledOrder', mappedBy: 'channeledPriceRules')]
    protected Collection $channeledOrders;

    #[ORM\OneToMany(mappedBy: 'channeledPriceRule', targetEntity: 'ChanneledDiscount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledDiscounts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: PriceRule::class, inversedBy: 'channeledPriceRules')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected PriceRule $priceRule;

    public function __construct()
    {
        $this->channeledOrders = new ArrayCollection();
        $this->channeledDiscounts = new ArrayCollection();
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
     * @return ChanneledPriceRule
     */
    public function addChanneledOrder(ChanneledOrder $channeledOrder): self
    {
        $this->channeledOrders->add($channeledOrder);

        return $this;
    }

    /**
     * @param Collection $channeledOrders
     * @return ChanneledPriceRule
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
     */
    public function removeChanneledOrder(ChanneledOrder $channeledOrder): void
    {
        $this->channeledOrders->removeElement($channeledOrder);
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

    public function getPriceRule(): PriceRule
    {
        return $this->priceRule;
    }

    public function addPriceRule(PriceRule $priceRule): void
    {
        $this->priceRule = $priceRule;
    }
}