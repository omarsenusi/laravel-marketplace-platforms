<?php

namespace Omarsenusi\MarketplacePlatforms\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Ignored = 'ignored';
    case DeadLetter = 'dead_letter';
}
