<?php

namespace Omarsenusi\MarketplacePlatforms\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Auth\TokenSet;
use Omarsenusi\MarketplacePlatforms\Auth\TokenStore;
use Omarsenusi\MarketplacePlatforms\Billing\SubscriptionSync;
use Omarsenusi\MarketplacePlatforms\Contracts\ProvisionsAccounts;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Events\StoreProfileRefreshed;
use Omarsenusi\MarketplacePlatforms\Events\StoreProvisioned;
use Omarsenusi\MarketplacePlatforms\Events\StoreReinstalled;
use Omarsenusi\MarketplacePlatforms\Jobs\RegisterZidEmbeddedToken;
use Omarsenusi\MarketplacePlatforms\Mail\StoreConnectedMail;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;

/**
 * Creates (or reactivates) everything an install needs: connection,
 * owner user (NULL password until claimed) + workspace via the
 * host's ProvisionsAccounts binding, store settings, claim + mail.
 * Exactly ONE store per workspace.
 *
 * Idempotent: webhooks redeliver, and reinstalls reuse the same
 * connection/user/workspace — never a duplicate account.
 */
final class Provisioner
{
    public function __construct(
        private readonly TokenStore $tokens,
        private readonly ClaimService $claims,
    ) {}

    public function provision(
        Platform $platform,
        StoreProfile $profile,
        ?TokenSet $tokenSet,
        string $source,
    ): ProvisionResult {
        $storeId = trim($profile->externalStoreId);

        $result = DB::transaction(function () use ($platform, $profile, $tokenSet, $source, $storeId): ProvisionResult {
            $lock = Cache::lock("platforms:provision:{$platform->id}:{$storeId}", 30);

            try {
                $lock->block(10);
            } catch (\Throwable $e) {
                Log::warning('platforms.provision.lock_timeout', [
                    'platform_id' => $platform->id,
                    'store_id' => $storeId,
                ]);

                throw $e;
            }

            try {
                $connection = PlatformConnection::query()
                    ->where('platform_id', $platform->id)
                    ->where('external_store_id', $storeId)
                    ->first();

                if ($connection !== null) {
                    return $this->reactivate($connection, $profile, $tokenSet, $source);
                }

                return $this->createNew($platform, $profile, $tokenSet, $source);
            } finally {
                $lock->release();
            }
        });

        // Post-commit follow-ups (outside the install transaction, so
        // dispatched jobs never race it): pending subscription
        // snapshot, Zid embedded-token registration.
        $this->afterCommit($platform, $result->connection);

        return new ProvisionResult(
            $result->connection->refresh(),
            $result->user,
            $result->isNewConnection,
            $result->isNewUser,
            $result->claimToken,
        );
    }

    /**
     * Profile-only re-sync for a LIVE connection (Salla app.updated):
     * refresh store settings only. Unlike provision() it never
     * reactivates an uninstalled store, never touches tokens, and
     * never sends claim/connected mail — app.updated fires for every
     * store on each app release.
     */
    public function refreshProfile(PlatformConnection $connection, StoreProfile $profile): void
    {
        if ($connection->uninstalled_at !== null) {
            return;
        }

        DB::transaction(fn () => $this->saveSettings($connection, $profile));

        StoreProfileRefreshed::dispatch($connection->refresh(), $profile);
    }

    private function afterCommit(Platform $platform, PlatformConnection $connection): void
    {
        $connection = $connection->refresh();

        try {
            app(SubscriptionSync::class)->applyStored($connection);
        } catch (\Throwable $e) {
            Log::warning('platforms.provision.subscription_failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Zid OAuth completed with tokens: register the per-store
        // embedded-launch token (non-fatal; the job retries).
        if ($platform->driver()->key() === 'zid'
            && $connection->workspace_id !== null
            && $connection->embedded_token_hash === null
            && $this->hasStoredTokens($connection->refresh())
        ) {
            RegisterZidEmbeddedToken::dispatch($connection->id);
        }
    }

    private function reactivate(
        PlatformConnection $connection,
        StoreProfile $profile,
        ?TokenSet $tokenSet,
        string $source,
    ): ProvisionResult {
        $connection->loadMissing('platform');
        $hadTokens = $this->hasStoredTokens($connection);

        $connection->forceFill([
            'uninstalled_at' => null,
            'status' => ($tokenSet !== null || $hadTokens) ? ConnectionStatus::Active : ConnectionStatus::PendingOAuth,
        ])->save();

        if ($tokenSet !== null) {
            $this->tokens->persistRefreshed($connection, $tokenSet);
            $connection->forceFill(['oauth_completed_at' => now()])->save();
        }

        $this->saveSettings($connection, $profile);

        // Pending Zid install that now has a real email: attach the
        // account/workspace the webhook phase could not create.
        $claimToken = null;
        $isNewUser = false;
        $user = $this->connectionUser($connection);

        if ($user === null && $profile->hasEmail()) {
            [$user, $claimToken] = $this->attachAccount($connection, $profile);
            $isNewUser = $user !== null && $user->wasRecentlyCreated;
        }

        if ($user !== null && WorkspaceClaim::isClaimed($user)) {
            $claimToken = null;
        } elseif ($user !== null && ! WorkspaceClaim::isClaimed($user) && $claimToken === null) {
            // Reinstall while still unclaimed: reuse the open claim if
            // there is one, else issue a fresh link so the merchant is
            // never stuck with an expired email.
            $open = WorkspaceClaim::query()
                ->where('user_id', $user->getKey())
                ->open()
                ->latest('created_at')
                ->first();

            if ($open === null) {
                $issued = $this->claims->issueAndSend($user, $connection, request()?->ip());
                $claimToken = $issued['token'];
            }
        }

        StoreReinstalled::dispatch($connection->refresh(), $source);

        return new ProvisionResult($connection->refresh(), $user, false, $isNewUser, $claimToken);
    }

    private function createNew(
        Platform $platform,
        StoreProfile $profile,
        ?TokenSet $tokenSet,
        string $source,
    ): ProvisionResult {
        $email = $this->normalizeEmail($profile->ownerEmail);

        $connection = PlatformConnection::create([
            'platform_id' => $platform->id,
            'workspace_id' => null,
            'external_store_id' => trim($profile->externalStoreId),
            'status' => $tokenSet !== null ? ConnectionStatus::Active : ConnectionStatus::PendingOAuth,
            'oauth_completed_at' => $tokenSet !== null ? now() : null,
        ]);

        if ($tokenSet !== null) {
            $connection->loadMissing('platform');
            $this->tokens->persistRefreshed($connection, $tokenSet);
        }

        $this->saveSettings($connection, $profile);

        if ($email === null) {
            // Webhook-before-OAuth (Zid): no user/workspace yet. The
            // OAuth callback attaches them when a real email arrives.
            StoreProvisioned::dispatch($connection->refresh(), false, $source);

            return new ProvisionResult($connection->refresh(), null, true, false, null);
        }

        $user = $this->accounts()->findUserByEmail($email);
        $claimToken = null;
        $isNewUser = false;

        if ($user !== null) {
            // Existing owner: NEW workspace for this store, login link
            // instead of a claim link.
            $workspace = $this->accounts()->createWorkspaceFor($user, $profile);
            $connection->forceFill(['workspace_id' => $workspace->getKey()])->save();
            $this->sendStoreConnectedMail($user, $connection);
        } else {
            $user = $this->accounts()->createStoreOwner($profile, $email);
            $user->wasRecentlyCreated = true;
            $isNewUser = true;
            $workspace = $this->accounts()->createWorkspaceFor($user, $profile);
            $connection->forceFill(['workspace_id' => $workspace->getKey()])->save();
            $issued = $this->claims->issueAndSend($user, $connection, request()?->ip());
            $claimToken = $issued['token'];
        }

        StoreProvisioned::dispatch($connection->refresh(), $isNewUser, $source);

        return new ProvisionResult($connection->refresh(), $user->refresh(), true, $isNewUser, $claimToken);
    }

    /**
     * Attach a fresh account to a pending connection (Zid OAuth
     * completing after the install webhook). Returns [user, claimToken].
     *
     * @return array{0: ?Model, 1: ?string}
     */
    private function attachAccount(PlatformConnection $connection, StoreProfile $profile): array
    {
        $email = $this->normalizeEmail($profile->ownerEmail);

        if ($email === null) {
            return [null, null];
        }

        $user = $this->accounts()->findUserByEmail($email);

        if ($user !== null) {
            $workspace = $this->accounts()->createWorkspaceFor($user, $profile);
            $connection->forceFill(['workspace_id' => $workspace->getKey()])->save();
            $this->sendStoreConnectedMail($user, $connection);

            return [$user, null];
        }

        $user = $this->accounts()->createStoreOwner($profile, $email);
        $user->wasRecentlyCreated = true;
        $workspace = $this->accounts()->createWorkspaceFor($user, $profile);
        $connection->forceFill(['workspace_id' => $workspace->getKey()])->save();
        $issued = $this->claims->issueAndSend($user, $connection, request()?->ip());

        return [$user, $issued['token']];
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function saveSettings(PlatformConnection $connection, StoreProfile $profile): void
    {
        $settings = $connection->storeSettings;
        $attributes = $settings?->toArray() ?? [];
        $attributes = $profile->mergeIntoSettings($attributes);
        $attributes['metadata'] = array_merge(
            is_array($settings?->metadata) ? $settings->metadata : [],
            $profile->metadata,
        );

        unset($attributes['id'], $attributes['connection_id'], $attributes['created_at'], $attributes['updated_at']);

        if ($settings !== null) {
            $settings->fill($attributes)->save();
        } else {
            $connection->storeSettings()->create($attributes);
        }
    }

    private function connectionUser(PlatformConnection $connection): ?Model
    {
        $connection->loadMissing('workspace');

        return $this->accounts()->workspaceOwner($connection->workspace);
    }

    private function hasStoredTokens(PlatformConnection $connection): bool
    {
        $tokens = is_array($connection->tokens) ? $connection->tokens : [];

        foreach (['access_token', 'manager_token', 'authorization_token'] as $key) {
            if (! empty($tokens[$key])) {
                return true;
            }
        }

        return false;
    }

    private function sendStoreConnectedMail(Model $user, PlatformConnection $connection): void
    {
        $locale = is_string($user->getAttribute('locale') ?? null) && $user->getAttribute('locale') !== ''
            ? $user->getAttribute('locale')
            : config('app.locale');

        Mail::to($user->getAttribute('email'))->locale($locale)->queue(
            new StoreConnectedMail($user, $connection)
        );
    }

    private function accounts(): ProvisionsAccounts
    {
        try {
            return app(ProvisionsAccounts::class);
        } catch (\Throwable $e) {
            throw new \LogicException(
                'No ['.ProvisionsAccounts::class.'] binding found. Bind an implementation in a service provider to provision store accounts.',
                0,
                $e,
            );
        }
    }
}
