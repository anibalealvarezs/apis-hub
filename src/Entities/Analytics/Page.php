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
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\HasLifecycleCallbacks]
class Page extends Entity
{
    #[ORM\Column(type: 'string', unique: true)]
    protected string $url;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $hostname = null;

    #[ORM\Column(type: 'string')]
    protected int|string $platformId;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'channeledAccounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Account $account;

    #[ORM\OneToMany(mappedBy: 'page', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    #[ORM\OneToMany(mappedBy: 'page', targetEntity: Post::class, orphanRemoval: true)]
    protected Collection $posts;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
        $this->posts = new ArrayCollection();
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
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     * @return Page
     */
    public function addPlatformId(int|string $platformId): self
    {
        $this->platformId = $platformId;
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

    /**
     * @return Account
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * @param Account|null $account
     * @return self
     */
    public function addAccount(?Account $account): self
    {
        $this->account = $account;

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
            $metricConfig->addPage($this);
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
            if ($metricConfig->getPage() === $this) {
                $metricConfig->addPage(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getPosts(): Collection
    {
        return $this->posts;
    }

    /**
     * Adds a metric config.
     * @param Post $post
     * @return self
     */
    public function addPost(Post $post): self
    {
        if (!$this->metricConfigs->contains($post)) {
            $this->metricConfigs->add($post);
            $post->addPage($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param Post $post
     * @return self
     */
    public function removePost(Post $post): self
    {
        if ($this->metricConfigs->contains($post)) {
            $this->metricConfigs->removeElement($post);
            if ($post->getPage() === $this) {
                $post->addPage(null);
            }
        }
        return $this;
    }
}
