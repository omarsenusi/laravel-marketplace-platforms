<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

enum SettlementStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Settled = 'settled';
    case Canceled = 'canceled';
}
