<?php

namespace Enums;

enum JobStatus: int
{
    case scheduled = 1;

    case processing = 2;

    case completed = 3;

    case failed = 4;

    case delayed = 5;

    public function getName(): string
    {
        return $this->name;
    }
}
