<?php

namespace Enums;

enum CrudMethods
{
    /**
     * @var string
     */
    case create;

    /**
     * @var string
     */
    case read;

    /**
     * @var string
     */
    case update;

    /**
     * @var string
     */
    case delete;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}
