<?php

namespace Omarsenusi\MarketplacePlatforms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Omarsenusi\MarketplacePlatforms\Auth\TokenStore;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;

/**
 * Refresh one connection's tokens under a 60s lock (Redis in prod,
 * array driver in tests). Tokens are re-read INSIDE the lock so two
 * workers never double-refresh on a stale copy.
 *
 * Failures: release with backoff (60, 600, 3600) up to 3 attempts,
 * then status=requires_reauth. 401/invalid_grant → requires_reauth
 * immediately (the refresh token is dead; retrying is pointless).
 */
class RefreshPlatformToken implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * Backoff seconds per attempt (attempt 1 → 60s, 2 → 600s, 3 → 3600s).
     *
     * @var list<int>
     */
    public const BACKOFF_SECONDS = [60, 600, 3600];

    public function __construct(public readonly string $connectionId) {}

    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(TokenStore $tokens): void
    {
        $lock = Cache::lock("platforms:refresh:{$this->connectionId}", 60);

        try {
            $lock->block((int) config('platforms.refresh.lock_wait_seconds', 10));
        } catch (\Throwable $e) {
            // Another worker holds the refresh; requeue shortly so the
            // schedule still converges without double-refreshing.
            self::dispatch($this->connectionId)->delay(now()->addSeconds(60));

            return;
        }

        try {
            $connection = PlatformConnection::query()->find($this->connectionId);

            if ($connection === null) {
                return;
            }

            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            // Re-read inside the lock: the row may have changed since
            // the job was dispatched (reinstall, uninstall, refresh).
            $connection->refresh();

            $stored = is_array($connection->tokens) ? $connection->tokens : [];
            if (empty($stored['refresh_token'])) {
                $this->markRequiresReauth($connection, 'missing_refresh_token');

                return;
            }

            try {
                $set = $connection->platform->driver()->refresh($connection);
            } catch (PermanentWebhookException $e) {
                $this->markRequiresReauth($connection, 'invalid_grant');

                return;
            }

            // Persist BOTH tokens before releasing the lock — Salla
            // refresh tokens are single-use.
            $tokens->persistRefreshed($connection->refresh(), $set);
        } catch (\Throwable $e) {
            Log::warning('platforms.token.refresh_failed', [
                'connection_id' => $this->connectionId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * After the final attempt the queue worker dead-letters the job;
     * flip the connection to requires_reauth on failure so the host
     * notices instead of silently serving stale tokens.
     */
    public function failed(\Throwable $e): void
    {
        $connection = PlatformConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status !== ConnectionStatus::Active) {
            return;
        }

        $this->markRequiresReauth($connection, 'refresh_failed');
    }

    private function markRequiresReauth(PlatformConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status' => ConnectionStatus::RequiresReauth,
            'refresh_scheduled_at' => null,
        ])->save();

        Log::warning('platforms.token.requires_reauth', [
            'connection_id' => $connection->id,
            'reason' => $reason,
        ]);
    }
}
