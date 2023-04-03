<?php

namespace Enums;

enum JobStatus: int
{
    /**
     * @var int
     */
    case processing = 1;

    /**
     * @var int
     */
    case completed = 2;

    /**
     * @var int
     */
    case failed = 3;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}
