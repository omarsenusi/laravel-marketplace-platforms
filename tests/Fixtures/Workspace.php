<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Factories\WorkspaceFactory;

/**
 * Minimal host workspace stand-in for tests: uuid id, name, and the
 * owning user id. Host apps point `platforms.models.workspace` at
 * their own model instead.
 */
class Workspace extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'workspaces';

    protected $fillable = ['name', 'owner_user_id'];

    protected static function newFactory(): WorkspaceFactory
    {
        return WorkspaceFactory::new();
    }

    public function newUniqueId(): string
    {
        return method_exists(Str::class, 'uuid7') ? (string) Str::uuid7() : (string) Str::orderedUuid();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
