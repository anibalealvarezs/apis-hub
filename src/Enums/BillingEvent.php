<?php

namespace Enums;

enum BillingEvent: string
{
    case IMPRESSIONS = 'IMPRESSIONS';
    case CLICKS = 'CLICKS';
    case LINK_CLICKS = 'LINK_CLICKS';
    case APP_INSTALLS = 'APP_INSTALLS';
    case VIDEO_VIEWS = 'VIDEO_VIEWS';
    case POST_ENGAGEMENT = 'POST_ENGAGEMENT';
    case PAGE_LIKES = 'PAGE_LIKES';
}
