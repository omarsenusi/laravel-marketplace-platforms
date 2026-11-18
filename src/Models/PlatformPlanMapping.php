<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Maps one marketplace-side plan key (Salla plan_name, Zid plan_id,
 * or the `__trial__` fallback) to a host-side plan id for one
 * platform. The host owns plans; `plan_id` is a plain uuid with no
 * foreign key.
 */
class PlatformPlanMapping extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    /**
     * Fallback key granting the trial plan when a marketplace trial's
     * own key has no mapping.
     */
    public const TRIAL_KEY = '__trial__';

    protected $fillable = [
        'platform_id',
        'external_plan_key',
        'label',
        'plan_id',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
