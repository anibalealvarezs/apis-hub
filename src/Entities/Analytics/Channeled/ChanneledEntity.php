<?php

namespace Entities\Analytics\Channeled;

use Entities\Entity;
use Doctrine\ORM\Mapping as ORM;
use Interfaces\ChannelInterface;

class ChanneledEntity extends Entity implements ChannelInterface
{
    #[ORM\Column(type: 'string')]
    protected int|string $platformId;

    #[ORM\Column(type: 'integer')]
    protected int $channel;

    #[ORM\Column(type: 'json')]
    protected array $data;

    /**
     * @return int|string
     */
    public function getPlatformId(): int|string
    {
        return $this->platformId;
    }

    /**
     * @param int|string $platformId
     * @return ChanneledEntity
     */
    public function addPlatformId(int|string $platformId): self
    {
        $this->platformId = $platformId;

        return $this;
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
     * @return ChanneledEntity
     */
    public function addChannel(int $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function addData(array $data): self
    {
        $this->data = $data;

        return $this;
    }
}