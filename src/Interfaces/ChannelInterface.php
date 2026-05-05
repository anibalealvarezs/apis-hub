<?php

    namespace Interfaces;

    use Entities\Analytics\Channel;

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
         * @return Channel
         */
        public function getChannel(): Channel;

        /**
         * @param Channel $channel
         * @return ChannelInterface
         */
        public function addChannel(Channel $channel): self;

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
