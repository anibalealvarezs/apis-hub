<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\PriceRule;
use Repositories\Channeled\ChanneledPriceRuleRepository;

#[ORM\Entity(repositoryClass: ChanneledPriceRuleRepository::class)]
#[ORM\Table(name: 'channeled_price_rules')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'idx_channeled_price_rules_platformId_channel_idx')]
#[ORM\Index(columns: ['platformId'], name: 'idx_channeled_price_rules_platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'idx_channeled_price_rules_platformCreatedAt_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledPriceRule extends ChanneledEntity
{
    // Relationships with channeled entities

    #[ORM\OneToMany(mappedBy: 'channeledPriceRule', targetEntity: ChanneledDiscount::class, orphanRemoval: true)]
    protected Collection $channeledDiscounts;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: PriceRule::class, inversedBy: 'channeledPriceRules')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected PriceRule $priceRule;

    public function __construct()
    {
        $this->channeledDiscounts = new ArrayCollection();
    }

    /**
     * @return Collection|null
     */
    public function getChanneledDiscounts(): ?Collection
    {
        return $this->channeledDiscounts;
    }

    /**
     * @param ChanneledDiscount $channeledDiscount
     * @return ChanneledPriceRule
     */
    public function addChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        if ($this->channeledDiscounts->contains($channeledDiscount)) {
            return $this;
        }

        $this->channeledDiscounts->add($channeledDiscount);
        $channeledDiscount->addChanneledPriceRule($this);

        return $this;
    }

    /**
     * @param Collection<ChanneledDiscount> $channeledDiscounts
     * @return ChanneledPriceRule
     */
    public function addChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->addChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    /**
     * @param ChanneledDiscount $channeledDiscount
     * @return ChanneledPriceRule
     */
    public function removeChanneledDiscount(ChanneledDiscount $channeledDiscount): self
    {
        if (!$this->channeledDiscounts->contains($channeledDiscount)) {
            return $this;
        }

        $this->channeledDiscounts->removeElement($channeledDiscount);

        if ($channeledDiscount->getChanneledPriceRule() !== $this) {
            return $this;
        }

        $channeledDiscount->addChanneledPriceRule(channeledPriceRule: null);

        return $this;
    }

    /**
     * @param Collection<ChanneledDiscount> $channeledDiscounts
     * @return ChanneledPriceRule
     */
    public function removeChanneledDiscounts(Collection $channeledDiscounts): self
    {
        foreach ($channeledDiscounts as $channeledDiscount) {
            $this->removeChanneledDiscount($channeledDiscount);
        }

        return $this;
    }

    /**
     * @return PriceRule
     */
    public function getPriceRule(): PriceRule
    {
        return $this->priceRule;
    }

    /**
     * @param PriceRule|null $priceRule
     * @return ChanneledPriceRule
     */
    public function addPriceRule(?PriceRule $priceRule): self
    {
        $this->priceRule = $priceRule;

        return $this;
    }
}
