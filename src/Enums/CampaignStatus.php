<?php

namespace Enums;

enum CampaignStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case DELETED = 'DELETED';
    case ARCHIVED = 'ARCHIVED';
}
