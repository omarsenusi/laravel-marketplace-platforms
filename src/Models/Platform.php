<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Database\Factories\PlatformFactory;
use Omarsenusi\MarketplacePlatforms\Drivers\DriverRegistry;
use Omarsenusi\MarketplacePlatforms\Drivers\PlatformDriver;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;

class Platform extends Model
{
    use HasFactory;
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'slug',
        'name',
        'driver',
        'app_id',
        'client_id',
        'auth_mode',
        'marketplace_app_url',
        'supports_marketplace_subscriptions',
        'status',
        'config',
    ];

    protected $casts = [
        'auth_mode' => AuthMode::class,
        'status' => PlatformStatus::class,
        'supports_marketplace_subscriptions' => 'boolean',
        'config' => 'array',
    ];

    protected static function newFactory(): PlatformFactory
    {
        return PlatformFactory::new();
    }

    public function secrets(): HasOne
    {
        return $this->hasOne(PlatformSecret::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function planMappings(): HasMany
    {
        return $this->hasMany(PlatformPlanMapping::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(PlatformWebhookEndpoint::class);
    }

    public function driver(): PlatformDriver
    {
        return app(DriverRegistry::class)->resolve($this->driver);
    }
}
