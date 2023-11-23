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
     */
    public function addPlatformId(int|string $platformId): self;

    /**
     * @return object
     */
    public function getChannel(): string;

    /**
     * @param int $channel
     */
    public function addChannel(int $channel): self;

    /**
     * @return array
     */
    public function getData(): array;

    /**
     * @param array $data
     */
    public function addData(array $data): self;
}