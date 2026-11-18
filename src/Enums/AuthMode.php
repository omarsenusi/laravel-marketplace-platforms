<?php

namespace Omarsenusi\MarketplacePlatforms\Enums;

enum AuthMode: string
{
    case Easy = 'easy';
    case Custom = 'custom';
    case OAuth = 'oauth';
}
