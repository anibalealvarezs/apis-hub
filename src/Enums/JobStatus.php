<?php

namespace Enums;

enum JobStatus: int
{
    /**
     * @var int
     */
    case scheduled = 1;

    /**
     * @var int
     */
    case processing = 2;

    /**
     * @var int
     */
    case completed = 3;

    /**
     * @var int
     */
    case failed = 4;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}
