<?php

namespace Omarsenusi\MarketplacePlatforms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Auth\TokenSet;
use Omarsenusi\MarketplacePlatforms\Enums\AuthMode;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Services\Provisioner;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /platforms/{slug}/callback — used by Zid (OAuth) and Salla
 * custom mode (authorization-code flow).
 *
 * No `state` validation for marketplace-initiated installs (Zid App
 * Market and Salla custom-mode installs start at the platform, not at
 * us). If a `state` param IS present and was issued by us (session),
 * it is validated.
 */
final class OAuthCallbackController
{
    public function __construct(private readonly Provisioner $provisioner) {}

    public function __invoke(Request $request, string $slug): Response|\Inertia\Response|RedirectResponse
    {
        $platform = Platform::query()->where('slug', $slug)->first();

        if ($platform === null || $platform->status !== PlatformStatus::Active) {
            return $this->error($platform?->name, 'This connection link is not available. Please install the app again from your store dashboard.');
        }

        if (! in_array($platform->auth_mode, [AuthMode::Custom, AuthMode::OAuth], true)) {
            return $this->error($platform->name, 'This store connects automatically from your store dashboard — no extra step is needed here.');
        }

        if (! $this->validState($request, $platform)) {
            return $this->error($platform->name, 'The connection request could not be verified. Please start the install again from your store dashboard.');
        }

        $code = $request->query('code');
        if (! is_string($code) || trim($code) === '') {
            return $this->error($platform->name, 'No authorization code was received. Please start the install again from your store dashboard.');
        }

        try {
            $tokenSet = $platform->driver()->exchangeCode($platform, trim($code));
        } catch (\Throwable $e) {
            Log::warning('platforms.oauth.exchange_failed', [
                'platform_id' => $platform->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error($platform->name, 'We could not complete the connection. Please try again from your store dashboard.');
        }

        try {
            $profile = $platform->driver()->fetchStoreProfile($this->transientConnection($platform, $tokenSet));
        } catch (\Throwable $e) {
            Log::warning('platforms.oauth.profile_failed', [
                'platform_id' => $platform->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error($platform->name, 'We connected your store but could not read its details. Please try again from your store dashboard.');
        }

        $result = $this->provisioner->provision($platform, $profile, $tokenSet, $platform->driver()->key().'.callback');
        $connection = $result->connection->refresh();
        $connection->loadMissing('workspace.owner');
        $user = $connection->workspace?->owner;

        if ($user === null) {
            return $this->error($platform->name, 'We connected your store but could not read its details. Please try again from your store dashboard.');
        }

        if (! WorkspaceClaim::isClaimed($user)) {
            // Zid rule: straight to the claim page, no waiting for the
            // email (provision already queued it as a backup).
            if (is_string($result->claimToken) && $result->claimToken !== '') {
                return redirect()->route('platforms.claim.show', ['token' => $result->claimToken]);
            }

            $issued = app(ClaimService::class)->issueAndSend($user, $connection, $request->ip());

            return redirect()->route('platforms.claim.show', ['token' => $issued['token']]);
        }

        if ($request->user()?->id === $user->id) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('login')->with('status', __('Store connected — sign in to continue.'));
    }

    private function validState(Request $request, Platform $platform): bool
    {
        $state = $request->query('state');

        if (! is_string($state) || $state === '') {
            // Marketplace-initiated install: no state issued, nothing
            // to validate.
            return true;
        }

        $expected = $request->session()->get("platforms.oauth_state.{$platform->slug}");

        if (! is_string($expected) || $expected === '') {
            // We never issued one — marketplace-initiated with an
            // opaque platform state. Accept.
            return true;
        }

        return hash_equals($expected, $state);
    }

    /**
     * An unsaved connection carrying the fresh tokens so the driver
     * profile fetch can go through PlatformApiClient::for() (the only
     * API path) before the real row exists.
     */
    private function transientConnection(Platform $platform, TokenSet $tokenSet): PlatformConnection
    {
        $connection = new PlatformConnection([
            'platform_id' => $platform->id,
            'external_store_id' => 'pending',
        ]);
        $connection->setRelation('platform', $platform);
        $connection->forceFill(['tokens' => $tokenSet->toStorageArray($platform->driver()->key())]);

        return $connection;
    }

    private function error(?string $platformName, string $message): \Inertia\Response
    {
        $slug = strtolower((string) $platformName);
        $logoKey = str_contains($slug, 'zid') ? 'zid' : (str_contains($slug, 'salla') ? 'salla' : 'store');

        return Inertia::render('platforms/connect-error', [
            'platform' => ['name' => $platformName ?? __('Your store'), 'logo' => $logoKey],
            'message' => $message,
            'hint' => __('Please try again from your :platform dashboard.', ['platform' => $platformName ?? __('store')]),
        ]);
    }
}
