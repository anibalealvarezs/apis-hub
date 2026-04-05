<?php

namespace Interfaces;

interface RateLimitedExceptionInterface
{
    /**
     * @return int Delay in seconds
     */
    public function getDelay(): int;
}
