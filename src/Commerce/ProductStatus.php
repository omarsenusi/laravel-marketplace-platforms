<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

enum ProductStatus: string
{
    case Sale = 'sale';
    case Out = 'out';
    case Hidden = 'hidden';
}
