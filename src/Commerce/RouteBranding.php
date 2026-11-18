<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * Branded-route presentation block (only for type=branded routes).
 */
final readonly class RouteBranding
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $logoUrl = null,
        public ?bool $combinable = null,
        public ?RouteBrandingPricing $pricing = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'logo_url' => $this->logoUrl,
            'combinable' => $this->combinable,
            'pricing' => $this->pricing?->toSallaArray(),
        ], static fn ($v): bool => $v !== null);
    }
}
