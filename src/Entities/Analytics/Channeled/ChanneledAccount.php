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
#[ORM\Index(columns: ['name', 'platformId', 'channel', 'type'], name: 'name_platformId_channel_type_idx')]
#[ORM\Index(columns: ['name', 'platformId', 'channel'], name: 'name_platformId_channel_idx')]
#[ORM\Index(columns: ['platformId', 'channel', 'type'], name: 'platformId_channel_type_idx')]
#[ORM\Index(columns: ['platformId', 'channel'], name: 'platformId_channel_idx')]
#[ORM\Index(columns: ['name', 'channel', 'type'], name: 'email_channel_type_idx')]
#[ORM\Index(columns: ['name', 'channel'], name: 'email_channel_idx')]
#[ORM\Index(columns: ['platformId', 'type'], name: 'platformId_type_idx')]
#[ORM\Index(columns: ['platformId'], name: 'platformId_idx')]
#[ORM\Index(columns: ['platformCreatedAt'], name: 'platformCreatedAt_idx')]
#[ORM\Index(columns: ['name', 'type'], name: 'name_type_idx')]
#[ORM\Index(columns: ['name'], name: 'name_idx')]
#[ORM\Index(columns: ['account_id'], name: 'account_id_idx')]
#[ORM\HasLifecycleCallbacks]
class ChanneledAccount extends ChanneledEntity
{
    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string', enumType: AccountEnum::class)]
    protected AccountEnum $type;

    // Relationships with non-channeled entities

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'channeledAccounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Account $account;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: ChanneledCampaign::class, orphanRemoval: true)]
    protected Collection $channeledCampaigns;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: ChanneledAdGroup::class, orphanRemoval: true)]
    protected Collection $channeledAdGroups;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: MetricConfig::class, orphanRemoval: true)]
    protected Collection $metricConfigs;

    #[ORM\OneToMany(mappedBy: 'channeledAccount', targetEntity: Post::class, orphanRemoval: true)]
    protected Collection $posts;

    public function __construct()
    {
        $this->channeledCampaigns = new ArrayCollection();
        $this->channeledAdGroups = new ArrayCollection();
        $this->metricConfigs = new ArrayCollection();
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
     * @return ChanneledAccount
     */
    public function addName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function addType(AccountEnum $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): AccountEnum
    {
        return $this->type;
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
        if (!$this->metricConfigs->contains($post)) {
            $this->metricConfigs->add($post);
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
        if ($this->metricConfigs->contains($post)) {
            $this->metricConfigs->removeElement($post);
            if ($post->getChanneledAccount() === $this) {
                $post->addChanneledAccount(null);
            }
        }
        return $this;
    }
}
