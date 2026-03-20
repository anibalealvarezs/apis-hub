<?php

namespace Entities\Analytics;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAccount;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Entity;
use Repositories\MetricConfigRepository;

#[ORM\Entity(repositoryClass: MetricConfigRepository::class)]
#[ORM\Table(name: 'metric_configs')]
#[ORM\Index(columns: ['channel', 'name', 'period', 'metric_date'], name: 'idx_metric_configs_metricDate_period_idx')]
#[ORM\Index(columns: ['channel', 'name', 'metric_date'], name: 'idx_metric_configs_metricDate_base_idx')]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metric_date', 'query_id', 'page_id', 'country_id', 'device_id'],
    name: 'idx_metric_configs_lookup_full_idx'
)]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metric_date', 'channeled_account_id'],
    name: 'idx_metric_configs_lookup_channeled_idx'
)]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metric_date', 'account_id'],
    name: 'idx_metric_configs_lookup_account_idx'
)]
#[ORM\UniqueConstraint(name: 'metric_config_signature_unique', columns: ['config_signature'])]
#[ORM\HasLifecycleCallbacks]
class MetricConfig extends Entity
{
    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string')]
    protected string $period;

    #[ORM\Column(name: 'metric_date', type: 'date')]
    protected DateTimeInterface $metricDate;

    #[ORM\OneToMany(mappedBy: 'metricConfig', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'account_id', onDelete: 'SET NULL')]
    protected ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'channeled_account_id', onDelete: 'SET NULL')]
    protected ?ChanneledAccount $channeledAccount = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'campaign_id', onDelete: 'SET NULL')]
    protected ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'channeled_campaign_id', onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAdGroup::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'channeled_ad_group_id', onDelete: 'SET NULL')]
    protected ?ChanneledAdGroup $channeledAdGroup = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAd::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'channeled_ad_id', onDelete: 'SET NULL')]
    protected ?ChanneledAd $channeledAd = null;

    #[ORM\ManyToOne(targetEntity: Creative::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'creative_id', onDelete: 'SET NULL')]
    protected ?Creative $creative = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'page_id', onDelete: 'SET NULL')]
    protected ?Page $page = null;

    #[ORM\ManyToOne(targetEntity: Query::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'query_id', onDelete: 'SET NULL')]
    protected ?Query $query = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'post_id', onDelete: 'SET NULL')]
    protected ?Post $post = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'product_id', onDelete: 'SET NULL')]
    protected ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'customer_id', onDelete: 'SET NULL')]
    protected ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(name: 'order_id', onDelete: 'SET NULL')]
    protected ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_id', onDelete: 'SET NULL')]
    protected ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', onDelete: 'SET NULL')]
    protected ?Device $device = null;

    #[ORM\Column(name: 'config_signature', type: 'string', length: 32, unique: true)]
    protected string $configSignature;

    /* #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $dimensions = []; */

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    /**
     * @return int
     */
    public function getChannel(): int
    {
        return $this->channel;
    }

    /**
     * @param int $channel
     * @return self
     */
    public function addChannel(int $channel): self
    {
        $this->channel = $channel;
        return $this;
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
     * @return self
     */
    public function addName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * @param string $period
     * @return self
     */
    public function addPeriod(string $period): self
    {
        $this->period = $period;
        return $this;
    }

    /**
     * @return DateTimeInterface
     */
    public function getMetricDate(): DateTimeInterface
    {
        return $this->metricDate;
    }

    /**
     * @param DateTimeInterface $metricDate
     * @return self
     */
    public function addMetricDate(DateTimeInterface $metricDate): self
    {
        $this->metricDate = $metricDate;
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
     * @return Campaign|null
     */
    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    /**
     * @param Campaign|null $campaign
     * @return self
     */
    public function addCampaign(?Campaign $campaign): self
    {
        $this->campaign = $campaign;
        return $this;
    }

    /**
     * @return ChanneledCampaign|null
     */
    public function getChanneledCampaign(): ?ChanneledCampaign
    {
        return $this->channeledCampaign;
    }

    /**
     * @param ChanneledCampaign|null $channeledCampaign
     * @return self
     */
    public function addChanneledCampaign(?ChanneledCampaign $channeledCampaign): self
    {
        $this->channeledCampaign = $channeledCampaign;
        return $this;
    }

    /**
     * @return ChanneledAdGroup|null
     */
    public function getChanneledAdGroup(): ?ChanneledAdGroup
    {
        return $this->channeledAdGroup;
    }

    /**
     * @param ChanneledAdGroup|null $channeledAdGroup
     * @return self
     */
    public function addChanneledAdGroup(?ChanneledAdGroup $channeledAdGroup): self
    {
        $this->channeledAdGroup = $channeledAdGroup;
        return $this;
    }

    /**
     * @return ChanneledAd|null
     */
    public function getChanneledAd(): ?ChanneledAd
    {
        return $this->channeledAd;
    }

    /**
     * @param ChanneledAd|null $channeledAd
     * @return self
     */
    public function addChanneledAd(?ChanneledAd $channeledAd): self
    {
        $this->channeledAd = $channeledAd;
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
     * @return Query|null
     */
    public function getQuery(): ?Query
    {
        return $this->query;
    }

    /**
     * @param Query|null $query
     * @return self
     */
    public function addQuery(?Query $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return Post|null
     */
    public function getPost(): ?Post
    {
        return $this->post;
    }

    /**
     * @param Post|null $post
     * @return self
     */
    public function addPost(?Post $post): self
    {
        $this->post = $post;
        return $this;
    }

    /**
     * @return Product|null
     */
    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * @param Product|null $product
     * @return self
     */
    public function addProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    /**
     * @return Customer|null
     */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    /**
     * @param Customer|null $customer
     * @return self
     */
    public function addCustomer(?Customer $customer): self
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->order;
    }

    /**
     * @param Order|null $order
     * @return self
     */
    public function addOrder(?Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    /**
     * @return Country|null
     */
    public function getCountry(): ?Country
    {
        return $this->country;
    }

    /**
     * @param Country|null $country
     * @return self
     */
    public function addCountry(?Country $country): self
    {
        $this->country = $country;
        return $this;
    }

    /**
     * @return Device|null
     */
    public function getDevice(): ?Device
    {
        return $this->device;
    }

    /**
     * @param Device|null $device
     * @return self
     */
    public function addDevice(?Device $device): self
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return Creative|null
     */
    public function getCreative(): ?Creative
    {
        return $this->creative;
    }

    /**
     * @param Creative|null $creative
     * @return self
     */
    public function addCreative(?Creative $creative): self
    {
        $this->creative = $creative;
        return $this;
    }

    /**
     * Gets the collection of metrics.
     * @return Collection
     */
    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    /**
     * Adds a metric config.
     * @param Metric $metric
     * @return self
     */
    public function addMetric(Metric $metric): self
    {
        if (!$this->metrics->contains($metric)) {
            $this->metrics->add($metric);
            $metric->addMetricConfig($this);
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getConfigSignature(): string
    {
        return $this->configSignature;
    }

    /**
     * @param string $configSignature
     * @return self
     */
    public function addConfigSignature(string $configSignature): self
    {
        $this->configSignature = $configSignature;
        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateSignature(): void
    {
        $this->configSignature = \Classes\KeyGenerator::generateMetricConfigKey(
            channel: $this->channel,
            name: $this->name,
            period: $this->period,
            metricDate: $this->metricDate,
            account: $this->account,
            channeledAccount: $this->channeledAccount,
            campaign: $this->campaign,
            channeledCampaign: $this->channeledCampaign,
            channeledAdGroup: $this->channeledAdGroup,
            channeledAd: $this->channeledAd,
            creative: $this->creative?->getCreativeId(),
            page: $this->page,
            query: $this->query,
            post: $this->post,
            product: $this->product,
            customer: $this->customer,
            order: $this->order,
            country: $this->country,
            device: $this->device
        );
    }
}
