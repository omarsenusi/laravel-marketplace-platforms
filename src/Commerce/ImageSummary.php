<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * @param  array<string, mixed>  $raw
 */
final readonly class ImageSummary
{
    public function __construct(
        public string $id,
        public ?string $url = null,
        public array $raw = [],
    ) {}
}
