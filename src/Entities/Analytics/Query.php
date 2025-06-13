<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\QueryRepository;

#[ORM\Entity(repositoryClass: QueryRepository::class)]
#[ORM\Table(name: 'queries')]
#[ORM\Index(columns: ['query'], name: 'query_idx')]
#[ORM\HasLifecycleCallbacks]
class Query extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $query;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\OneToMany(mappedBy: 'query', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    /**
     * Gets the search query string.
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Sets the search query string.
     * @param string $query
     * @return self
     */
    public function addQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Gets the query-specific data (e.g., google_search_console_country).
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the query-specific data.
     * @param array|null $data
     * @return self
     */
    public function addData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }
}