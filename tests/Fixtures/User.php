<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Omarsenusi\MarketplacePlatforms\Tests\Fixtures\Factories\UserFactory;

/**
 * Minimal host user stand-in for tests: bigint id, nullable password
 * (marketplace-installed owners start with NULL until claimed), phone,
 * locale, and the default workspace pointer the package reads.
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'locale',
        'email_verified_at',
        'default_workspace_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
