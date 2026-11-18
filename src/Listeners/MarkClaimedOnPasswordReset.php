<?php

namespace Omarsenusi\MarketplacePlatforms\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Events\WorkspaceClaimed;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;

/**
 * A null-password (marketplace-installed) user going through the
 * host's normal forgot-password flow counts as claiming: mark the
 * email verified and retire any open claim links so they cannot be
 * reused after the password exists.
 */
final class MarkClaimedOnPasswordReset
{
    public function __construct(private readonly ClaimService $claims) {}

    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof Model) {
            return;
        }

        $userClass = (string) config('platforms.models.user');

        if (! $user instanceof $userClass) {
            return;
        }

        $user->refresh();

        if ($user->getAttribute('email_verified_at') === null && $this->hasVerifiedAtColumn($user)) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->claims->invalidateOpenClaims($user);

        $openClaim = WorkspaceClaim::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->first();

        if ($openClaim?->workspace_id !== null) {
            WorkspaceClaimed::dispatch($openClaim, 'password_reset');
        }
    }

    private function hasVerifiedAtColumn(Model $user): bool
    {
        try {
            return Schema::connection($user->getConnectionName())->hasColumn($user->getTable(), 'email_verified_at');
        } catch (\Throwable) {
            return false;
        }
    }
}
