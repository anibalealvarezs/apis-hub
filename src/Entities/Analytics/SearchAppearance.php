<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Classes\Overrides\GoogleApi\SearchConsoleApi\Enums\SearchAppearance as SearchAppearanceEnum;

#[ORM\Entity]
#[ORM\Table(name: 'search_appearances')]
#[ORM\UniqueConstraint(name: 'search_appearance_type_unique', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
class SearchAppearance extends Entity
{
    #[ORM\Column(type: 'string', enumType: SearchAppearanceEnum::class)]
    protected SearchAppearanceEnum $type;

    #[ORM\OneToMany(mappedBy: 'query', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    public function addType(SearchAppearanceEnum $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): SearchAppearanceEnum
    {
        return $this->type;
    }
}
