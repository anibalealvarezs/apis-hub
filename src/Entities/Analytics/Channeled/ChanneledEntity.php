<?php

namespace Entities\Analytics\Channeled;

use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;

#[ORM\MappedSuperclass]
class ChanneledEntity extends Entity implements ChannelInterface
{
    #[ORM\Column(name: 'platform_id', type: 'string')]
    protected int|string $platformId;

    #[ORM\ManyToOne(targetEntity: \Entities\Analytics\Channel::class)]
    #[ORM\JoinColumn(name: 'channel', referencedColumnName: 'id', nullable: false)]
    protected \Entities\Analytics\Channel $channel;

    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $data = null;

    #[ORM\Column(name: 'platform_created_at', type: 'datetime', nullable: true)]
    protected ?\DateTimeInterface $platformCreatedAt = null;

    /**
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     * @return static
     */
    public function addPlatformId(int|string $platformId): static
    {
        $this->platformId = $platformId;
        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getPlatformCreatedAt(): ?\DateTimeInterface
    {
        return $this->platformCreatedAt;
    }

    /**
     * @param \DateTimeInterface|null $platformCreatedAt
     * @return static
     */
    public function addPlatformCreatedAt(?\DateTimeInterface $platformCreatedAt): static
    {
        $this->platformCreatedAt = $platformCreatedAt;
        return $this;
    }

    /**
     * @return \Entities\Analytics\Channel
     */
    public function getChannel(): \Entities\Analytics\Channel
    {
        return $this->channel;
    }

    /**
     * @param \Entities\Analytics\Channel $channel
     * @return static
     */
    public function addChannel(\Entities\Analytics\Channel $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @param array|null $data
     * @return static
     */
    public function addData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }
}
