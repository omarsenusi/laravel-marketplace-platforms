<?php

namespace Omarsenusi\MarketplacePlatforms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One claim link per row: only the sha256 of the 64-char token is
 * stored. A claim is open when used_at is NULL and expires_at is in
 * the future and the user still has a NULL password.
 */
class WorkspaceClaim extends Model
{
    use HasUuids;

    public function newUniqueId(): string
    {
        // UUIDv7 (time-ordered) on Laravel 12+, ordered fallback otherwise.
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    protected $fillable = [
        'workspace_id',
        'user_id',
        'connection_id',
        'token_hash',
        'token_encrypted',
        'expires_at',
        'used_at',
        'sent_at',
        'reminder_count',
        'created_ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'sent_at' => 'datetime',
        'reminder_count' => 'integer',
        'token_encrypted' => 'encrypted',
    ];

    /**
     * Never serialize the recoverable token (or its hash).
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'token_encrypted'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo((string) config('platforms.models.workspace'), 'workspace_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('platforms.models.user'), 'user_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'connection_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * Claim state lives on the user (password NOT NULL). Static check so
     * callers never duplicate the rule. Accepts any host user model.
     */
    public static function isClaimed(mixed $user): bool
    {
        return $user !== null && $user->getAttribute('password') !== null;
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
