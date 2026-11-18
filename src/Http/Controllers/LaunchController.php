<?php

namespace Omarsenusi\MarketplacePlatforms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;

/**
 * GET|POST /platforms/{slug}/launch — the "Open app" URL set in the
 * Salla/Zid partner dashboard.
 *
 * Proven path (?token=…): the driver verifies the short-lived
 * embedded token (Salla introspection / Zid registered uuid) and the
 * proven store id wins over any store_id/merchant query. A verified
 * merchant gets a fresh claim link (no mail) or a dashboard link —
 * rendered in the frameable launch-embedded page (never auto-login).
 *
 * Unproven path: an unauthenticated query param alone NEVER hands out
 * a claim link. Proven owners (logged in as the workspace owner) go
 * to the dashboard; everyone else gets a masked-email notice while
 * the real link travels by email (rate-limited 1 per 5 min per
 * connection). That behaviour holds for forgeable requests too.
 */
final class LaunchController
{
    public function __construct(private readonly ClaimService $claims) {}

    public function __invoke(Request $request, string $slug): Response|RedirectResponse
    {
        $platform = Platform::query()->where('slug', $slug)->first();

        if ($platform === null || $platform->status !== PlatformStatus::Active) {
            abort(404);
        }

        $token = $request->query('token');

        if (is_string($token) && trim($token) !== '') {
            return $this->proven($request, $platform, trim($token));
        }

        return $this->unproven($request, $platform, false);
    }

    private function proven(Request $request, Platform $platform, string $token): Response|RedirectResponse
    {
        $storeId = null;

        try {
            $storeId = $platform->driver()->verifyEmbeddedToken($platform, $token);
        } catch (\Throwable $e) {
            Log::warning('platforms.launch.verify_failed', [
                'platform_id' => $platform->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! is_string($storeId) || $storeId === '') {
            // Forged/expired token: fall back to the unproven behaviour.
            // Never a claim URL in the response here.
            Log::warning('platforms.launch.unproven_token', [
                'platform_id' => $platform->id,
            ]);

            return $this->unproven($request, $platform, true);
        }

        $connection = PlatformConnection::query()
            ->where('platform_id', $platform->id)
            ->where('external_store_id', $storeId)
            ->first();

        if ($connection === null || $connection->uninstalled_at !== null) {
            return $this->connectError($platform);
        }

        $connection->loadMissing('workspace.owner');
        $owner = $connection->workspace?->owner;

        if ($owner === null) {
            return $this->connectError($platform);
        }

        if (WorkspaceClaim::isClaimed($owner)) {
            return Inertia::render('platforms/launch-embedded', [
                'platform' => ['name' => $platform->name, 'logo' => $platform->driver()->key()],
                'mode' => 'dashboard',
                'url' => PublicUrl::route('dashboard'),
                'maskedEmail' => ClaimService::maskEmail($owner->email),
            ]);
        }

        $issued = $this->claims->issueWithoutMail($owner, $connection, $request->ip());

        return Inertia::render('platforms/launch-embedded', [
            'platform' => ['name' => $platform->name, 'logo' => $platform->driver()->key()],
            'mode' => 'claim',
            'url' => PublicUrl::route('platforms.claim.show', ['token' => $issued['token']]),
            'maskedEmail' => ClaimService::maskEmail($owner->email),
        ]);
    }

    private function unproven(Request $request, Platform $platform, bool $fromToken): Response|RedirectResponse
    {
        $storeId = $request->query('store_id', $request->query('merchant'));

        if (! is_string($storeId) || trim($storeId) === '') {
            // No store hint at all: without a proven token there is
            // nothing to resolve. Token callers get the friendly page
            // (not a bare 404); plain callers keep the legacy 404.
            if ($fromToken) {
                return $this->connectError($platform);
            }

            abort(404);
        }

        $connection = PlatformConnection::query()
            ->where('platform_id', $platform->id)
            ->where('external_store_id', trim($storeId))
            ->first();

        if ($connection === null) {
            if ($fromToken) {
                return $this->connectError($platform);
            }

            abort(404);
        }

        $connection->loadMissing('workspace.owner');
        $owner = $connection->workspace?->owner;

        if ($owner !== null && $request->user()?->id === $owner->id) {
            return redirect()->route('dashboard');
        }

        if ($owner !== null && WorkspaceClaim::isClaimed($owner)) {
            return redirect()->route('login')->with('status', __('Store connected — sign in to continue.'));
        }

        $email = null;
        $sent = false;

        if ($owner !== null && ! WorkspaceClaim::isClaimed($owner)) {
            $limiterKey = "platforms:launch:{$connection->id}";

            if (! RateLimiter::tooManyAttempts($limiterKey, 1)) {
                RateLimiter::hit($limiterKey, 300);
                $this->claims->issueAndSend($owner, $connection, $request->ip());
                $sent = true;
            }

            $email = ClaimService::maskEmail($owner->email);
        }

        if ($owner === null) {
            return $this->connectError($platform);
        }

        return Inertia::render('platforms/launch', [
            'platform' => ['name' => $platform->name, 'logo' => $platform->driver()->key()],
            'maskedEmail' => $email ?? ClaimService::maskEmail($owner->email),
            'sent' => $sent,
            'resendUrl' => $request->fullUrl(),
        ]);
    }

    private function connectError(Platform $platform): Response
    {
        return Inertia::render('platforms/connect-error', [
            'platform' => ['name' => $platform->name, 'logo' => $platform->driver()->key()],
            'message' => __('This store is not fully connected yet. Please complete the install from your store dashboard.'),
            'hint' => __('Please try again from your :platform dashboard.', ['platform' => $platform->name]),
        ]);
    }
}
