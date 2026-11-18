<?php

namespace Omarsenusi\MarketplacePlatforms\Enums;

enum PlatformStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
