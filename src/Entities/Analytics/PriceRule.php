<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledPriceRule;
use Entities\Entity;
use Repositories\PriceRuleRepository;

#[ORM\Entity(repositoryClass: PriceRuleRepository::class)]
#[ORM\Table(name: 'priceRules')]
#[ORM\Index(columns: ['priceRuleId'], name: 'priceRuleId_idx')]
#[ORM\HasLifecycleCallbacks]
class PriceRule extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected int|string $priceRuleId;

    #[ORM\OneToMany(mappedBy: 'priceRule', targetEntity: ChanneledPriceRule::class, orphanRemoval: true)]
    protected Collection $channeledPriceRules;

    public function __construct()
    {
        $this->channeledPriceRules = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getPriceRuleId(): string
    {
        return $this->priceRuleId;
    }

    /**
     * @param string $priceRuleId
     * @return PriceRule
     */
    public function addPriceRuleId(string $priceRuleId): self
    {
        $this->priceRuleId = $priceRuleId;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledPriceRules(): ?Collection
    {
        return $this->channeledPriceRules;
    }

    /**
     * @param ChanneledPriceRule $channeledPriceRule
     * @return PriceRule
     */
    public function addChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): self
    {
        if ($this->channeledPriceRules->contains($channeledPriceRule)) {
            return $this;
        }

        $this->channeledPriceRules->add($channeledPriceRule);
        $channeledPriceRule->addPriceRule($this);

        return $this;
    }

    /**
     * @param Collection $channeledPriceRules
     * @return PriceRule
     */
    public function addChanneledPriceRules(Collection $channeledPriceRules): self
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->addChanneledPriceRule($channeledPriceRule);
        }

        return $this;
    }

    /**
     * @param ChanneledPriceRule $channeledPriceRule
     * @return PriceRule
     */
    public function removeChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): self
    {
        if (!$this->channeledPriceRules->contains($channeledPriceRule)) {
            return $this;
        }

        $this->channeledPriceRules->removeElement($channeledPriceRule);

        if ($channeledPriceRule->getPriceRule() !== $this) {
            return $this;
        }

        $channeledPriceRule->addPriceRule(priceRule: null);

        return $this;
    }

    /**
     * @param Collection $channeledPriceRules
     * @return PriceRule
     */
    public function removeChanneledPriceRules(Collection $channeledPriceRules): self
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->removeChanneledPriceRule($channeledPriceRule);
        }

        return $this;
    }
}
