<?php

namespace Entities\Analytics;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Entity;
use Repositories\AccountRepository;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
#[ORM\Index(columns: ['name'], name: 'name_idx')]
#[ORM\HasLifecycleCallbacks]
class Account extends Entity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\OneToMany(mappedBy: 'account', targetEntity: ChanneledAccount::class, orphanRemoval: true)]
    protected Collection $channeledAccounts;

    #[ORM\OneToMany(mappedBy: 'account', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    #[ORM\OneToMany(mappedBy: 'account', targetEntity: Page::class, orphanRemoval: true)]
    protected Collection $pages;

    #[ORM\OneToMany(mappedBy: 'account', targetEntity: Post::class, orphanRemoval: true)]
    protected Collection $posts;

    public function __construct()
    {
        $this->channeledAccounts = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
        $this->pages = new ArrayCollection();
        $this->posts = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Account
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|null
     */
    public function getChanneledAccounts(): ?Collection
    {
        return $this->channeledAccounts;
    }

    /**
     * @param ChanneledAccount $channeledAccount
     * @return Account
     */
    public function addChanneledAccount(ChanneledAccount $channeledAccount): self
    {
        if ($this->channeledAccounts->contains($channeledAccount)) {
            return $this;
        }

        $this->channeledAccounts->add($channeledAccount);
        $channeledAccount->addAccount($this);

        return $this;
    }

    /**
     * @param Collection $channeledAccounts
     * @return Account
     */
    public function addChanneledAccounts(Collection $channeledAccounts): self
    {
        foreach ($channeledAccounts as $channeledAccount) {
            $this->addChanneledAccount($channeledAccount);
        }

        return $this;
    }

    /**
     * @param ChanneledAccount $channeledAccount
     * @return Account
     */
    public function removeChanneledAccount(ChanneledAccount $channeledAccount): self
    {
        if (!$this->channeledAccounts->contains($channeledAccount)) {
            return $this;
        }

        $this->channeledAccounts->removeElement($channeledAccount);

        if ($channeledAccount->getAccount() !== $this) {
            return $this;
        }

        $channeledAccount->addAccount(account: null);

        return $this;
    }

    /**
     * @param Collection $channeledAccounts
     * @return Account
     */
    public function removeChanneledAccounts(Collection $channeledAccounts): self
    {
        foreach ($channeledAccounts as $channeledAccount) {
            $this->removeChanneledAccount($channeledAccount);
        }

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
            $metricConfig->addAccount($this);
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
            if ($metricConfig->getAccount() === $this) {
                $metricConfig->addAccount(null);
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
        if (!$this->posts->contains($post)) {
            $this->posts->add($post);
            $post->addAccount($this);
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
        if ($this->posts->contains($post)) {
            $this->posts->removeElement($post);
            if ($post->getAccount() === $this) {
                $post->addAccount(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getPages(): Collection
    {
        return $this->pages;
    }

    /**
     * Adds a metric config.
     * @param Page $pages
     * @return self
     */
    public function addPage(Page $pages): self
    {
        if (!$this->pages->contains($pages)) {
            $this->pages->add($pages);
            $pages->addAccount($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param Page $page
     * @return self
     */
    public function removePage(Page $page): self
    {
        if ($this->pages->contains($page)) {
            $this->pages->removeElement($page);
            if ($page->getAccount() === $this) {
                $page->addAccount(null);
            }
        }
        return $this;
    }
}
