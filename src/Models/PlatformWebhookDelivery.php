<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Enums\DeliveryStatus;

class PlatformWebhookDelivery extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'endpoint_id',
        'platform_id',
        'connection_id',
        'external_event_id',
        'topic',
        'headers',
        'payload',
        'status',
        'failure_reason',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'received_at',
        'processing_started_at',
        'processed_at',
        'expires_at',
        'duration_ms',
    ];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'headers' => 'array',
        'payload' => 'array',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'duration_ms' => 'integer',
        'next_retry_at' => 'datetime',
        'received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
        'expires_at' => 'datetime',
        'needs_action' => 'boolean',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(PlatformWebhookEndpoint::class, 'endpoint_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'connection_id');
    }
}
