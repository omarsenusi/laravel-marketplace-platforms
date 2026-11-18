<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

enum StockMode: string
{
    case Increment = 'increment';
    case Decrement = 'decrement';
    case Overwrite = 'overwrite';
}
