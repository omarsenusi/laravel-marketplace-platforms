<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformStoreSettings extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'connection_id',
        'store_name',
        'store_domain',
        'store_url',
        'owner_name',
        'owner_email',
        'owner_phone',
        'currency',
        'locale',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'connection_id');
    }
}
