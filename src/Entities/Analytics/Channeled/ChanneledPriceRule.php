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

    #[ORM\OneToMany(mappedBy: 'channeledPriceRule', targetEntity: 'ChanneledDiscount', orphanRemoval: true)]
    protected Collection $channeledDiscounts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: PriceRule::class, inversedBy: 'channeledPriceRules')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected PriceRule $priceRule;

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
        if (!$this->channeledDiscounts->contains($channeledDiscount)) {
            $this->channeledDiscounts->add($channeledDiscount);
            $channeledDiscount->addChanneledPriceRule($this);
        }

        return $this;
    }

    public function addChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->addChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    public function removeChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        if ($this->channeledDiscounts->contains($channeledDiscount)) {
            $this->channeledDiscounts->removeElement($channeledDiscount);
            if ($channeledDiscount->getChanneledPriceRule() === $this) {
                $channeledDiscount->addChanneledPriceRule(null);
            }
        }

        return $this;
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

    public function addPriceRule(?PriceRule $priceRule): self
    {
        $this->priceRule = $priceRule;

        return $this;
    }
}