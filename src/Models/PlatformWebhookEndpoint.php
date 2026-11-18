<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PlatformWebhookEndpoint extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'platform_id',
        'name',
        'key',
        'verification_strategy',
        'verification_config',
        'log_retention_seconds',
        'max_retries',
        'topic_filter',
        'enabled',
    ];

    protected $casts = [
        'verification_config' => 'array',
        'topic_filter' => 'array',
        'log_retention_seconds' => 'integer',
        'max_retries' => 'integer',
        'enabled' => 'boolean',
    ];

    /**
     * The key is the last URL segment Salla/Zid post to
     * (/webhooks/platforms/{slug}/{key}). It is generated once, server
     * side, as a random UUIDv4 (unguessable, unique) and never changes:
     * renaming it would silently break the URL registered in the
     * partner dashboard.
     */
    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            if (! is_string($endpoint->key) || trim($endpoint->key) === '') {
                $endpoint->key = (string) Str::uuid();
            }
        });

        static::updating(function (self $endpoint): void {
            if ($endpoint->isDirty('key')) {
                throw new \LogicException('Webhook endpoint keys are immutable.');
            }
        });
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PlatformWebhookDelivery::class, 'endpoint_id');
    }
}
