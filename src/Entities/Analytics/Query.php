<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\QueryRepository;

#[ORM\Entity(repositoryClass: QueryRepository::class)]
#[ORM\Table(name: 'queries')]
#[ORM\Index(columns: ['query'], name: 'idx_queries_query_idx')]
#[ORM\HasLifecycleCallbacks]
class Query extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $query;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\OneToMany(mappedBy: 'query', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
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

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getMetricConfigs(): Collection
    {
        return $this->metricConfigs;
    }

    /**
     * Adds a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function addMetricConfig(MetricConfig $metricConfig): self
    {
        if (!$this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->add($metricConfig);
            $metricConfig->addQuery($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param MetricConfig $metricConfig
     * @return self
     */
    public function removeMetricConfig(MetricConfig $metricConfig): self
    {
        if ($this->metricConfigs->contains($metricConfig)) {
            $this->metricConfigs->removeElement($metricConfig);
            if ($metricConfig->getQuery() === $this) {
                $metricConfig->addQuery(null);
            }
        }
        return $this;
    }
}
