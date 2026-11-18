<?php

namespace Omarsenusi\MarketplacePlatforms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Omarsenusi\MarketplacePlatforms\Auth\PlatformApiClient;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Webhooks\PermanentWebhookException;

/**
 * Register the per-store embedded-launch token with Zid
 * (POST {api.baseUrl}/managers/embedded-apps-token {"token": uuid}).
 * Only the sha256 is stored — the raw uuid travels in the iframe URL.
 *
 * Non-fatal: transport failures retry (3x with backoff); a 403 means
 * the app lacks the embedded_apps_tokens_write scope and stops.
 */
class RegisterZidEmbeddedToken implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function __construct(
        public readonly string $connectionId,
    ) {}

    public function handle(): void
    {
        $connection = PlatformConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->embedded_token_hash !== null) {
            return;
        }

        if ($connection->status !== ConnectionStatus::Active) {
            return;
        }

        $token = (string) Str::uuid();

        try {
            $response = PlatformApiClient::for($connection)->post('/managers/embedded-apps-token', [
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::warning('platforms.embedded.zid_register_failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($response->status() === 403) {
            Log::warning('platforms.embedded.zid_missing_scope', [
                'connection_id' => $connection->id,
                'hint' => 'Grant the embedded_apps_tokens_write scope to register embedded-launch tokens.',
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('platforms.embedded.zid_register_failed', [
                'connection_id' => $connection->id,
                'status' => $response->status(),
            ]);

            throw new \RuntimeException("Zid embedded token registration failed (http {$response->status()}).");
        }

        $connection->forceFill(['embedded_token_hash' => hash('sha256', $token)])->save();

        Log::info('platforms.embedded.zid_registered', [
            'connection_id' => $connection->id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        if ($e instanceof PermanentWebhookException) {
            return;
        }

        Log::warning('platforms.embedded.zid_register_exhausted', [
            'connection_id' => $this->connectionId,
            'error' => $e->getMessage(),
        ]);
    }
}
