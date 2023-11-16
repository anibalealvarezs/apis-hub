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
#[ORM\HasLifecycleCallbacks]
class PriceRule extends Entity
{
    #[ORM\OneToMany(mappedBy: 'priceRule', targetEntity: '\Entities\Analytics\Channeled\ChanneledPriceRule', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $channeledPriceRules;

    public function __construct()
    {
        $this->channeledPriceRules = new ArrayCollection();
    }

    public function getChanneledPriceRules(): ?Collection
    {
        return $this->channeledPriceRules;
    }

    public function addChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): self
    {
        $this->channeledPriceRules->add($channeledPriceRule);

        return $this;
    }

    public function addChanneledPriceRules(Collection $channeledPriceRules): self
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->addChanneledPriceRule($channeledPriceRule);
        }

        return $this;
    }

    public function removeChanneledPriceRule(ChanneledPriceRule $channeledPriceRule): void
    {
        $this->channeledPriceRules->removeElement($channeledPriceRule);
    }

    public function removeChanneledPriceRules(Collection $channeledPriceRules): void
    {
        foreach ($channeledPriceRules as $channeledPriceRule) {
            $this->removeChanneledPriceRule($channeledPriceRule);
        }
    }
}