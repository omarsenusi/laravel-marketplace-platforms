<?php

namespace Omarsenusi\MarketplacePlatforms\Catalog;

/**
 * Remembers the last deferred gateway answer so callers of find()
 * (which returns null on miss and throws nothing) can tell a
 * rate-limited upstream apart from a genuinely missing product.
 */
trait RemembersDeferral
{
    protected ?int $deferredAfter = null;

    public function lastDeferredAfter(): ?int
    {
        return $this->deferredAfter;
    }

    protected function markDeferred(int $retryAfter): void
    {
        $this->deferredAfter = max(0, $retryAfter);
    }

    protected function markResolved(): void
    {
        $this->deferredAfter = null;
    }
}
