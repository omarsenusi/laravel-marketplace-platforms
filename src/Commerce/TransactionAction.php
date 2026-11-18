<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

enum TransactionAction: string
{
    case Refund = 'refund';
    case Void = 'void';
    case Reverse = 'reverse';
}
