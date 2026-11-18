<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

enum StockIdentifierType: string
{
    case Id = 'id';
    case VariantId = 'variant_id';
    case Sku = 'sku';
}
