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
#[ORM\Index(columns: ['channel', 'name', 'period', 'metricDate'], name: 'channel_name_period_metricDate_idx')]
#[ORM\Index(columns: ['channel', 'name', 'metricDate'], name: 'channel_name_metricDate_idx')]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metricDate', 'query_id', 'page_id', 'country_id', 'device_id'],
    name: 'gsc_metricConfig_lookup_idx'
)]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metricDate', 'channeledAccount_id'],
    name: 'channeled_account_metricConfig_lookup_idx'
)]
#[ORM\Index(
    columns: ['channel', 'name', 'period', 'metricDate', 'account_id'],
    name: 'account_metricConfig_lookup_idx'
)]
#[ORM\UniqueConstraint(name: 'metric_config_unique', columns: [
    'channel',
    'name',
    'period',
    'metricDate',
    'channeledAccount_id',
    'channeledCampaign_id',
    'channeledAdGroup_id',
    'channeledAd_id',
    'page_id',
    'query_id',
    'post_id',
    'product_id',
    'customer_id',
    'order_id',
    'country_id',
    'device_id',
])]
#[ORM\HasLifecycleCallbacks]
class MetricConfig extends Entity
{
    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'string')]
    protected string $period;

    #[ORM\Column(type: 'date')]
    protected DateTimeInterface $metricDate;

    #[ORM\OneToMany(mappedBy: 'metricConfig', targetEntity: Metric::class, orphanRemoval: true)]
    protected Collection $metrics;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAccount::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAccount $channeledAccount = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAdGroup::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAdGroup $channeledAdGroup = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAd::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAd $channeledAd = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Page $page = null;

    #[ORM\ManyToOne(targetEntity: Query::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Query $query = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Post $post = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'metricConfigs')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Device $device = null;

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

    // METRIC SHOULDN'T BE REMOVABLE FROM METRIC CONFIG
    /**
     * Removes a metric config.
     * @param Metric $metric
     * @return self
     */
    /* public function removeMetric(Metric $metric): self
    {
        if ($this->metrics->contains($metric)) {
            $this->metrics->removeElement($metric);
            if ($metric->getMetricConfig() === $this) {
                $metric->addMetricConfig(null);
            }
        }
        return $this;
    } */
}