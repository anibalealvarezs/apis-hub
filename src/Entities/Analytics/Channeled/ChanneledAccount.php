<?php

namespace Entities\Analytics\Channeled;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Account;
use Entities\Analytics\MetricConfig;
use Entities\Analytics\Post;
use Enums\Account as AccountEnum;
use Repositories\Channeled\ChanneledAccountRepository;

#[ORM\Entity(repositoryClass: ChanneledAccountRepository::class)]
#[ORM\Table(name: 'channeled_accounts')]
#[ORM\UniqueConstraint(name: 'channeled_accounts_platform_channel_unique', columns: ['platform_id', 'channel'])]
#[ORM\Index(columns: ['name', 'channel', 'type'], name: 'idx_cha_name_channel_type_idx')]
#[ORM\Index(columns: ['name', 'channel'], name: 'idx_cha_name_channel_idx')]
#[ORM\Index(columns: ['platform_id', 'type'], name: 'idx_cha_platform_id_type_idx')]
#[ORM\Index(columns: ['platform_id'], name: 'idx_cha_platform_id_idx')]
#[ORM\Index(columns: ['platform_created_at'], name: 'idx_cha_platform_created_at_idx')]
#[ORM\Index(columns: ['name', 'type'], name: 'idx_cha_name_type_idx')]
#[ORM\Index(columns: ['name'], name: 'idx_cha_name_idx')]
#[ORM\Index(columns: ['account_id'], name: 'idx_cha_account_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAccount extends ChanneledEntity
{
    #[ORM\Column(type: 'text')]
    protected string $name;

    #[ORM\Column(type: 'string')]
    protected string $type;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $enabled = true;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'channeledAccounts')]
    #[ORM\JoinColumn(name: 'account_id', onDelete: 'cascade')]
    protected Account $account;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: ChanneledCampaign::class, orphanRemoval: true)]
    protected Collection $channeledCampaigns;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: ChanneledAdGroup::class, orphanRemoval: true)]
    protected Collection $channeledAdGroups;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: Post::class, orphanRemoval: true)]
    protected Collection $posts;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: ChanneledAd::class, orphanRemoval: true)]
    protected Collection $channeledAds;

    public function __construct()
    {
        $this->channeledCampaigns = new ArrayCollection();
        $this->channeledAdGroups = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
        $this->posts = new ArrayCollection();
        $this->channeledAds = new ArrayCollection();
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
     * @return ChanneledAccount
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function addType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return static
     */
    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
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
     * @return ChanneledAccount
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
    public function getChanneledCampaigns(): Collection
    {
        return $this->channeledCampaigns;
    }

    /**
     * Adds a metric config.
     * @param ChanneledCampaign $channeledCampaign
     * @return self
     */
    public function addChanneledCampaign(ChanneledCampaign $channeledCampaign): self
    {
        if (!$this->channeledCampaigns->contains($channeledCampaign)) {
            $this->channeledCampaigns->add($channeledCampaign);
            $channeledCampaign->addChanneledAccount($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param ChanneledCampaign $channeledCampaign
     * @return self
     */
    public function removeChanneledCampaign(ChanneledCampaign $channeledCampaign): self
    {
        if ($this->channeledCampaigns->contains($channeledCampaign)) {
            $this->channeledCampaigns->removeElement($channeledCampaign);
            if ($channeledCampaign->getChanneledAccount() === $this) {
                $channeledCampaign->addChanneledAccount(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of metric configs.
     * @return Collection
     */
    public function getChanneledAdGroups(): Collection
    {
        return $this->channeledAdGroups;
    }

    /**
     * Adds a metric config.
     * @param ChanneledAdGroup $channeledAdGroup
     * @return self
     */
    public function addChanneledAdGroup(ChanneledAdGroup $channeledAdGroup): self
    {
        if (!$this->channeledAdGroups->contains($channeledAdGroup)) {
            $this->channeledAdGroups->add($channeledAdGroup);
            $channeledAdGroup->addChanneledAccount($this);
        }
        return $this;
    }

    /**
     * Removes a metric config.
     * @param ChanneledAdGroup $channeledAdGroup
     * @return self
     */
    public function removeChanneledAdGroup(ChanneledAdGroup $channeledAdGroup): self
    {
        if ($this->channeledAdGroups->contains($channeledAdGroup)) {
            $this->channeledAdGroups->removeElement($channeledAdGroup);
            if ($channeledAdGroup->getChanneledAccount() === $this) {
                $channeledAdGroup->addChanneledAccount(null);
            }
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
            $metricConfig->addChanneledAccount($this);
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
            if ($metricConfig->getChanneledAccount() === $this) {
                $metricConfig->addChanneledAccount(null);
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
            $post->addChanneledAccount($this);
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
            if ($post->getChanneledAccount() === $this) {
                $post->addChanneledAccount(null);
            }
        }
        return $this;
    }

    /**
     * Gets the collection of channeled ads.
     * @return Collection
     */
    public function getChanneledAds(): Collection
    {
        return $this->channeledAds;
    }

    /**
     * Adds a channeled ad.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function addChanneledAd(ChanneledAd $channeledAd): self
    {
        if (!$this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->add($channeledAd);
            $channeledAd->addChanneledAccount($this);
        }
        return $this;
    }

    /**
     * Removes a channeled ad.
     * @param ChanneledAd $channeledAd
     * @return self
     */
    public function removeChanneledAd(ChanneledAd $channeledAd): self
    {
        if ($this->channeledAds->contains($channeledAd)) {
            $this->channeledAds->removeElement($channeledAd);
            if ($channeledAd->getChanneledAccount() === $this) {
                $channeledAd->addChanneledAccount(null);
            }
        }
        return $this;
    }
}
