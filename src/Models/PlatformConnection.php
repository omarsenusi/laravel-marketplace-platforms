<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Database\Factories\PlatformConnectionFactory;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;

class PlatformConnection extends Model
{
    use HasFactory;
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'platform_id',
        'workspace_id',
        'external_store_id',
        'status',
        'tokens',
        'token_expires_at',
        'refresh_scheduled_at',
        'oauth_completed_at',
        'subscription',
        'subscription_event_at',
        'embedded_token_hash',
        'uninstalled_at',
    ];

    protected $hidden = [
        'tokens',
        'embedded_token_hash',
    ];

    protected $casts = [
        'status' => ConnectionStatus::class,
        'tokens' => 'encrypted:array',
        'token_expires_at' => 'datetime',
        'refresh_scheduled_at' => 'datetime',
        'oauth_completed_at' => 'datetime',
        'subscription' => 'array',
        'subscription_event_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    protected static function newFactory(): PlatformConnectionFactory
    {
        return PlatformConnectionFactory::new();
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo((string) config('platforms.models.workspace'), 'workspace_id');
    }

    public function storeSettings(): HasOne
    {
        return $this->hasOne(PlatformStoreSettings::class, 'connection_id');
    }
}
