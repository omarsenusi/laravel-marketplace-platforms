<?php

namespace Omarsenusi\MarketplacePlatforms\Auth;

use Illuminate\Support\Carbon;
use Omarsenusi\MarketplacePlatforms\Drivers\DriverRegistry;
use Omarsenusi\MarketplacePlatforms\Events;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * The ONLY read/write path for PlatformConnection.tokens (encrypted
 * array cast). Package code must never pass raw token arrays around —
 * go through read()/write()/clear() so the Salla-simple vs Zid-dual
 * mapping stays in one place.
 *
 * Stored shapes:
 *   salla: ['access_token'=>…, 'refresh_token'=>…, 'scope'=>…]
 *   zid:   ['manager_token'=>…, 'authorization_token'=>…, 'refresh_token'=>…]
 * Absolute expiry + refresh schedule live in the token_expires_at /
 * refresh_scheduled_at COLUMNS (queryable), not inside tokens.
 */
final class TokenStore
{
    public function __construct(private readonly DriverRegistry $drivers) {}

    public function read(PlatformConnection $connection): TokenSet
    {
        $tokens = is_array($connection->tokens) ? $connection->tokens : [];

        $str = static fn (string $key): ?string => isset($tokens[$key]) && is_string($tokens[$key]) && $tokens[$key] !== ''
            ? $tokens[$key]
            : null;

        return new TokenSet(
            accessToken: $str('access_token'),
            refreshToken: $str('refresh_token'),
            managerToken: $str('manager_token'),
            authorizationToken: $str('authorization_token'),
            scope: $str('scope'),
            expiresAt: $connection->token_expires_at?->toImmutable(),
        );
    }

    public function write(PlatformConnection $connection, TokenSet $set): void
    {
        $driver = $connection->platform->driver()->key();

        $tokens = $driver === 'zid'
            ? array_filter([
                'manager_token' => $set->managerToken,
                'authorization_token' => $set->authorizationToken,
                'refresh_token' => $set->refreshToken,
            ])
            : array_filter([
                'access_token' => $set->accessToken,
                'refresh_token' => $set->refreshToken,
                'scope' => $set->scope,
            ]);

        $connection->forceFill(['tokens' => $tokens])->save();
    }

    public function clear(PlatformConnection $connection): void
    {
        $connection->forceFill([
            'tokens' => null,
            'token_expires_at' => null,
            'refresh_scheduled_at' => null,
        ])->save();
    }

    /**
     * Persist BOTH tokens (refresh tokens are single-use — the old one
     * dies with the response) and (re)compute the refresh schedule.
     */
    public function persistRefreshed(PlatformConnection $connection, TokenSet $set): void
    {
        $this->write($connection, $set);
        $this->scheduleRefresh($connection, $set->expiresIn ?? $this->ttlFromExpiry($connection, $set));

        Events\TokensRefreshed::dispatch($connection->refresh());
    }

    /**
     * token_expires_at = now + ttl;
     * refresh_scheduled_at = token_expires_at − lead (see leadSeconds()),
     * minus up to 1h jitter so many stores don't refresh at once — never
     * later than the lead, never earlier than now.
     */
    public function scheduleRefresh(PlatformConnection $connection, ?int $ttlSeconds): void
    {
        $ttl = ($ttlSeconds !== null && $ttlSeconds > 0) ? $ttlSeconds : 31_536_000;

        $connection->refresh();
        $expiresAt = Carbon::now()->addSeconds($ttl);

        $connection->forceFill([
            'token_expires_at' => $expiresAt,
            'refresh_scheduled_at' => $this->refreshAt($connection, $expiresAt, $ttl, jitter: true),
        ])->save();
    }

    /**
     * Recompute only the refresh time from the stored expiry (after the
     * host changes the platform's lead time). No-op without an expiry.
     */
    public function reschedule(PlatformConnection $connection): void
    {
        $expiresAt = $connection->token_expires_at;

        if ($expiresAt === null) {
            return;
        }

        $ttl = max(1, (int) Carbon::now()->diffInSeconds($expiresAt, false));

        $connection->forceFill([
            'refresh_scheduled_at' => $this->refreshAt($connection, Carbon::parse($expiresAt), $ttl, jitter: false),
        ])->save();
    }

    /**
     * Hours before expiry to refresh: platform config
     * tokens.refreshLeadHours, else the driver default, else 72h.
     */
    public static function leadHours(Platform $platform): int
    {
        $config = is_array($platform->config) ? $platform->config : [];
        $defaults = $platform->driver()->defaultConfig();
        $hours = $config['tokens']['refreshLeadHours']
            ?? $defaults['tokens']['refreshLeadHours']
            ?? 72;

        return max(1, min(8760, (int) $hours));
    }

    private function refreshAt(PlatformConnection $connection, Carbon $expiresAt, int $ttl, bool $jitter): Carbon
    {
        $lead = self::leadHours($connection->platform) * 3600;

        // A lead longer than the token's life would refresh immediately
        // in a loop: cap it so at least 10% of the lifetime is used.
        $lead = min($lead, (int) floor($ttl * 0.9));

        $at = $expiresAt->copy()->subSeconds($lead);

        if ($jitter) {
            $at->subSeconds(random_int(0, (int) min(3600, max(0, $lead * 0.05))));
        }

        return $at->isPast() ? Carbon::now() : $at;
    }

    private function ttlFromExpiry(PlatformConnection $connection, TokenSet $set): ?int
    {
        if ($set->expiresAt === null) {
            return null;
        }

        $ttl = $set->expiresAt->getTimestamp() - time();

        return $ttl > 0 ? $ttl : null;
    }
}
