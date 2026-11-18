<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformSecret extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'platform_id',
        'client_secret',
        'webhook_secret',
        'partner_api_key',
        'metadata',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
        'partner_api_key',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'partner_api_key' => 'encrypted',
        'metadata' => 'array',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
