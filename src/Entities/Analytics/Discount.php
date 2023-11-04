<?php

namespace Entities\Analytics;

use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;
use Repositories\DiscountRepository;

#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table(name: 'discounts')]
#[ORM\HasLifecycleCallbacks]
class Discount extends Entity implements ChannelInterface
{
    #[ORM\Column]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected string $data;

    #[ORM\ManyToOne(targetEntity:"Order", inversedBy: 'discounts')]
    #[ORM\JoinColumn(onDelete: 'cascade')]
    protected Order $order;

    /**
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     */
    public function addPlatformId(int|string $platformId): void
    {
        $this->platformId = $platformId;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @param int $channel
     */
    public function addChannel(int $channel): void
    {
        $this->channel = $channel;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @param string $data
     */
    public function addData(string $data): void
    {
        $this->data = $data;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function addOrder(Order $order): void
    {
        $this->order = $order;
    }
}