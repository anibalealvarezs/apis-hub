<?php

namespace Interfaces;

use Doctrine\Common\Collections\ArrayCollection;
use Enums\Channel;
use Symfony\Component\HttpFoundation\Response;

interface RequestInterface
{
    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    public static function process(ArrayCollection $channeledCollection): Response;

    /**
     * @return Channel[]
     */
    public static function supportedChannels(): array;
}
