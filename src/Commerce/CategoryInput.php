<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * New-category payload for POST /categories.
 */
final readonly class CategoryInput
{
    public function __construct(
        public string $name,
        public ?CategoryStatus $status = null,
        public ?string $image = null,
        public ?int $parentId = null,
        public ?int $sortOrder = null,
        public ?string $metadataTitle = null,
        public ?string $metadataDescription = null,
        public ?string $metadataUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'status' => $this->status?->value,
            'image' => $this->image,
            'parent_id' => $this->parentId,
            'sort_order' => $this->sortOrder,
            'metadata_title' => $this->metadataTitle,
            'metadata_description' => $this->metadataDescription,
            'metadata_url' => $this->metadataUrl,
        ], static fn ($v): bool => $v !== null);
    }
}
