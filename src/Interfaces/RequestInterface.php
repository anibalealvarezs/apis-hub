<?php

namespace Interfaces;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpFoundation\Response;

interface RequestInterface
{
    /**
     * @param ArrayCollection $channeledCollection
     * @return Response
     */
    static function process(ArrayCollection $channeledCollection): Response;
}