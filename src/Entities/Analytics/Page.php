<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Entity;
use Repositories\PageRepository;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'pages')]
#[ORM\Index(columns: ['url'], name: 'url_idx')]
#[ORM\HasLifecycleCallbacks]
class Page extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $url;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $hostname = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\OneToMany(mappedBy: 'page', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    /**
     * Gets the page URL or path.
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Sets the page URL or path.
     * @param string $url
     * @return self
     */
    public function addUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Gets the page title.
     * @return string|null
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Sets the page title.
     * @param string|null $title
     * @return self
     */
    public function addTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Gets the hostname.
     * @return string|null
     */
    public function getHostname(): ?string
    {
        return $this->hostname;
    }

    /**
     * Sets the hostname.
     * @param string|null $hostname
     * @return self
     */
    public function addHostname(?string $hostname): self
    {
        $this->hostname = $hostname;
        return $this;
    }

    /**
     * Gets the page-specific data.
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the page-specific data.
     * @param array|null $data
     * @return self
     */
    public function addData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            'Page(id=%s, url=%s)',
            $this->getId() ?? 'new',
            $this->getUrl() ?? 'unknown'
        );
    }
}