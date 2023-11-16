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
    public function addPlatformId(int|string $platformId): void;

    /**
     * @return object
     */
    public function getChannel(): string;

    /**
     * @param int $channel
     */
    public function addChannel(int $channel): void;

    /**
     * @return object
     */
    public function getData(): string;

    /**
     * @param string $data
     */
    public function addData(string $data): void;
}