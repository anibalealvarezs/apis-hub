<?php

namespace Enums;

enum QueryBuilderType: string
{
    /**
     * @var int
     */
    case SELECT = 'select';

    /**
     * @var int
     */
    case COUNT = 'count';

    /**
     * @var int
     */
    case LAST = 'last';
}
