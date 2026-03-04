<?php

namespace Enums;

enum Period: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Lifetime = 'lifetime';
}
