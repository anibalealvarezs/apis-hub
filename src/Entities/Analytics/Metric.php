<?php

namespace Entities\Analytics;

use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Entities\Analytics\Channeled\ChanneledAd;
use Entities\Analytics\Channeled\ChanneledAdGroup;
use Entities\Analytics\Channeled\ChanneledCampaign;
use Entities\Analytics\Channeled\ChanneledMetric;
use Entities\Entity;
use Repositories\MetricRepository;

#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: 'metrics')]
#[ORM\Index(columns: ['channel', 'name', 'period', 'metricDate'], name: 'channel_name_period_metricDate_idx')]
#[ORM\Index(columns: ['channel', 'name', 'metricDate'], name: 'channel_name_metricDate_idx')]
#[ORM\UniqueConstraint(name: 'metric_unique', columns: [
    'channel',
    'name',
    'period',
    'metricDate',
    'campaign_id',
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
    'device_id'
])]
#[ORM\HasLifecycleCallbacks]
class Metric extends Entity
{
    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'string')]
    protected string $name;

    #[ORM\Column(type: 'float')]
    protected float $value;

    #[ORM\Column(type: 'string')]
    protected string $period;

    #[ORM\Column(type: 'date')]
    protected DateTimeInterface $metricDate;

    #[ORM\OneToMany(mappedBy: 'metric', targetEntity: ChanneledMetric::class, orphanRemoval: true)]
    protected Collection $channeledMetrics;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledCampaign::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledCampaign $channeledCampaign = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAdGroup::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAdGroup $channeledAdGroup = null;

    #[ORM\ManyToOne(targetEntity: ChanneledAd::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ChanneledAd $channeledAd = null;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Page $page = null;

    #[ORM\ManyToOne(targetEntity: Query::class, inversedBy: 'metrics', )]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Query $query = null;

    #[ORM\ManyToOne(targetEntity: Post::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Post $post = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'metrics')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Country $country = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Device $device = null;

    #[ORM\Column(type: 'json', nullable: true)]
    protected array $metadata = [];

    /* #[ORM\Column(type: 'json', nullable: true)]
    protected array $dimensions = []; */

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
     * @return float
     */
    public function getValue(): float
    {
        return $this->value;
    }

    /**
     * @param float $value
     * @return self
     */
    public function addValue(float $value): self
    {
        $this->value = $value;
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
        $trace = (new \Exception())->getTraceAsString();
        if ($page && !$page->getId()) {
            error_log("Metric::addPage: Unmanaged Page: url=" . ($page->getUrl() ?? 'unknown') . ", trace=" . $trace);
        } elseif ($page) {
            error_log("Metric::addPage: Setting page: id={$page->getId()}, url={$page->getUrl()}, trace=" . $trace);
        } else {
            error_log("Metric::addPage: Setting page to null, trace=" . $trace);
        }
        $this->page = $page;
        return $this;
    }

    public function __set($name, $value)
    {
        if ($name === 'page') {
            error_log("Metric: Direct page property access detected: url=" . ($value->getUrl() ?? 'unknown') . ", trace=" . (new \Exception())->getTraceAsString());
            $this->addPage($value);
        }
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
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array $metadata
     * @return self
     */
    public function addMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * @return Collection
     */
    public function __toString(): string
    {
        return sprintf(
            'Metric[id=%s, name=%s, channel=%d, metricDate=%s]',
            $this->id ?? 'new',
            $this->name,
            $this->channel,
            $this->metricDate->format('Y-m-d')
        );
    }
}