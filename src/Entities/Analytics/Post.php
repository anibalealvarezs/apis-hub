<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Entity;
use Repositories\PostRepository;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
#[ORM\Index(columns: ['post_id'], name: 'idx_posts_id_idx')]
#[ORM\Index(columns: ['post_id', 'page_id'], name: 'idx_posts_id_page_idx')]
#[ORM\Index(columns: ['post_id', 'page_id', 'account_id'], name: 'idx_posts_full_idx')]
#[ORM\Index(columns: ['post_id', 'page_id', 'channeled_account_id'], name: 'idx_posts_id_page_caccount_idx')]
#[ORM\Index(columns: ['post_id', 'page_id', 'account_id', 'channeled_account_id'], name: 'idx_posts_full_caccount_idx')]
#[ORM\Index(columns: ['post_id', 'account_id'], name: 'idx_posts_id_account_idx')]
#[ORM\Index(columns: ['post_id', 'account_id', 'channeled_account_id'], name: 'idx_posts_id_account_caccount_idx')]
#[ORM\Index(columns: ['post_id', 'channeled_account_id'], name: 'idx_posts_id_caccount_idx')]
#[ORM\UniqueConstraint(name: 'post_unique', columns: [
    'post_id',
    'page_id',
    'account_id',
    'channeled_account_id'
])]
#[ORM\HasLifecycleCallbacks]
class Post extends Entity
{
    #[ORM\Column(name: 'post_id', type: 'string', unique: true)]
    protected string $postId;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = [];

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(name: 'channeled_account_id', onDelete: 'SET NULL')]
    protected ?ChanneledAccount $channeledAccount = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'posts')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Page $page = null;

    #[ORM\OneToMany(mappedBy: 'post', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    public function __construct()
    {
        $this->metricConfigs = new ArrayCollection();
    }

    /**
     * Gets the platform-specific post ID.
     * @return string
     */
    public function getPostId(): string
    {
        return $this->postId;
    }

    /**
     * Sets the platform-specific post ID.
     * @param string $postId
     * @return self
     */
    public function addPostId(string $postId): self
    {
        $this->postId = $postId;
        return $this;
    }

    /**
     * Gets the post-specific data (e.g., caption, media_url, facebook_post_type).
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Sets the post-specific data.
     * @param array|null $data
     * @return self
     */
    public function addData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @return Account|null
     */
    public function getAccount(): ?Account
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
     * @return ChanneledAccount|null
     */
    public function getChanneledAccount(): ?ChanneledAccount
    {
        return $this->channeledAccount;
    }

    /**
     * @param ChanneledAccount|null $channeledAccount
     * @return self
     */
    public function addChanneledAccount(?ChanneledAccount $channeledAccount): self
    {
        $this->channeledAccount = $channeledAccount;
        return $this;
    }

    /**
     * @return Page|null
     */
    public function getPage(): ?Page
    {
        return $this->page;
    }

    /**
     * @param Page|null $page
     * @return self
     */
    // In Entities\Analytics\Metric.php
    public function addPage(?Page $page): self
    {
        $this->page = $page;
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
            $metricConfig->addPost($this);
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
            if ($metricConfig->getPost() === $this) {
                $metricConfig->addPost(null);
            }
        }
        return $this;
    }
}
