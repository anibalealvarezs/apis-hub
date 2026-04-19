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
     * @return \Entities\Analytics\Channel
     */
    public function getChannel(): \Entities\Analytics\Channel;

    /**
     * @param \Entities\Analytics\Channel $channel
     * @return ChannelInterface
     */
    public function addChannel(\Entities\Analytics\Channel $channel): self;

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
