<?php

namespace Interfaces;

interface ChannelInterface
{
    /**
     * @return int|string
     */
    public function getPlatformId(): int|string;

    /**
     * @param int|string $platformId
     * @return ChannelInterface
     */
    public function addPlatformId(int|string $platformId): self;

    /**
     * @return int
     */
    public function getChannel(): int;

    /**
     * @param int $channel
     * @return ChannelInterface
     */
    public function addChannel(int $channel): self;

    /**
     * @return array|null
     */
    public function getData(): ?array;

    /**
     * @param array|null $data
     * @return ChannelInterface
     */
    public function addData(?array $data): self;
}
