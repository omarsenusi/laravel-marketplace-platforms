<?php

namespace Omarsenusi\MarketplacePlatforms\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Mail\ClaimWorkspaceMail;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;

/**
 * Issues claim links (64-char token, sha256 stored, TTL from
 * platforms.claim.ttl_hours) and emails them. Every new claim
 * invalidates the user's older open claims so only one link works.
 *
 * The user is any host model (see `platforms.models.user`); only
 * `id`, `email`, `password`, `locale` and `default_workspace_id`
 * attributes are read.
 */
final class ClaimService
{
    /**
     * @return array{claim: WorkspaceClaim, token: string}
     */
    public function issueClaim(Model $user, ?PlatformConnection $connection = null, ?string $ip = null): array
    {
        $this->invalidateOpenClaims($user);

        $token = Str::random(64);

        $claim = WorkspaceClaim::create([
            'workspace_id' => $user->getAttribute('default_workspace_id') ?? $connection?->workspace_id,
            'user_id' => $user->getKey(),
            'connection_id' => $connection?->id,
            'token_hash' => WorkspaceClaim::hashToken($token),
            'token_encrypted' => $token,
            'expires_at' => now()->addHours((int) config('platforms.claim.ttl_hours', 72)),
            'sent_at' => now(),
            'reminder_count' => 0,
            'created_ip' => $ip,
        ]);

        return ['claim' => $claim->refresh(), 'token' => $token];
    }

    /**
     * The owner's CURRENT setup link for the host to surface: reuse the
     * open claim when its token is recoverable (so the emailed link keeps
     * working), otherwise mint a fresh one without mailing.
     *
     * @return array{url: string, expires_at: string|null, reused: bool}
     */
    public function currentLink(Model $user, ?PlatformConnection $connection = null, ?string $ip = null): array
    {
        $open = WorkspaceClaim::query()
            ->where('user_id', $user->getKey())
            ->open()
            ->whereNotNull('token_encrypted')
            ->latest('created_at')
            ->first();

        $token = null;

        if ($open !== null) {
            try {
                $token = $open->token_encrypted;
            } catch (\Throwable) {
                $token = null; // APP_KEY rotated: fall through to a new link.
            }
        }

        if (is_string($token) && $token !== '' && WorkspaceClaim::hashToken($token) === $open->token_hash) {
            return [
                'url' => PublicUrl::route('platforms.claim.show', ['token' => $token]),
                'expires_at' => $open->expires_at?->toIso8601String(),
                'reused' => true,
            ];
        }

        ['claim' => $claim, 'token' => $token] = $this->issueClaim($user, $connection, $ip);

        return [
            'url' => PublicUrl::route('platforms.claim.show', ['token' => $token]),
            'expires_at' => $claim->expires_at?->toIso8601String(),
            'reused' => false,
        ];
    }

    public function sendClaimMail(Model $user, WorkspaceClaim $claim, string $rawToken, ?PlatformConnection $connection = null): void
    {
        $locale = is_string($user->getAttribute('locale') ?? null) && $user->getAttribute('locale') !== ''
            ? $user->getAttribute('locale')
            : config('app.locale');

        Mail::to($user->getAttribute('email'))->locale($locale)->queue(
            new ClaimWorkspaceMail($user, $claim, $rawToken, $connection)
        );
    }

    /**
     * Issue + send in one step. Returns the raw token so OAuth
     * callbacks can redirect straight to the claim page.
     *
     * @return array{claim: WorkspaceClaim, token: string}
     */
    public function issueAndSend(Model $user, ?PlatformConnection $connection = null, ?string $ip = null): array
    {
        ['claim' => $claim, 'token' => $token] = $this->issueClaim($user, $connection, $ip);
        $this->sendClaimMail($user, $claim, $token, $connection);

        return ['claim' => $claim, 'token' => $token];
    }

    /**
     * Fresh claim WITHOUT emailing (embedded launch hands the link
     * to an already-verified merchant inside the dashboard iframe).
     * Still invalidates older open claims so only one link works.
     *
     * @return array{claim: WorkspaceClaim, token: string}
     */
    public function issueWithoutMail(Model $user, ?PlatformConnection $connection = null, ?string $ip = null): array
    {
        return $this->issueClaim($user, $connection, $ip);
    }

    public function invalidateOpenClaims(Model $user): int
    {
        return WorkspaceClaim::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }

    public function expireConnectionClaims(PlatformConnection $connection): int
    {
        return WorkspaceClaim::query()
            ->where('connection_id', $connection->id)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);
    }

    public static function maskEmail(?string $email): string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $first = mb_substr($local, 0, 1);

        return ($first !== '' ? $first : '*').'***@'.$domain;
    }
}
