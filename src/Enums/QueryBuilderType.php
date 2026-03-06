<?php

namespace Enums;

enum QueryBuilderType: string
{
    case SELECT = 'select';

    case COUNT = 'count';

    case LAST = 'last';
    case AGGREGATE = 'aggregate';
    case CUSTOM = 'custom';
}
