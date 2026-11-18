<?php

namespace Omarsenusi\MarketplacePlatforms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Events\WorkspaceClaimed;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;

/**
 * GET  /claim/{token}         show the claim page (host renders `auth/claim-workspace`)
 * POST /claim/{token}         set the password → verified + login + dashboard
 * POST /claim/{token}/resend  fresh link for expired tokens (neutral response)
 */
final class ClaimController
{
    public function __construct(private readonly ClaimService $claims) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $claim = $this->findClaim($token);

        if ($claim === null) {
            abort(404);
        }

        $user = $claim->user;

        if (WorkspaceClaim::isClaimed($user)) {
            return redirect()->route('login')->with('status', __('This account is already set up — please sign in.'));
        }

        $claim->loadMissing(['workspace', 'connection.platform', 'connection.storeSettings']);

        return Inertia::render('auth/claim-workspace', array_merge(
            $this->payload($claim, $token),
            ['expired' => ! $claim->isOpen()],
        ));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $claim = $this->findClaim($token);

        if ($claim === null) {
            abort(404);
        }

        $user = $claim->user()->first();

        if (WorkspaceClaim::isClaimed($user)) {
            return redirect()->route('login')->with('status', __('This account is already set up — please sign in.'));
        }

        if (! $claim->isOpen()) {
            return redirect()->route('platforms.claim.show', ['token' => $token]);
        }

        $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => $request->input('password'),
            'email_verified_at' => $user->getAttribute('email_verified_at') ?? now(),
        ])->save();

        $claim->forceFill(['used_at' => now()])->save();
        $this->claims->invalidateOpenClaims($user->refresh());

        Auth::login($user->refresh(), true);
        $request->session()->regenerate();

        WorkspaceClaimed::dispatch($claim->refresh(), 'claim_page');

        return redirect()->intended(config('platforms.claim.home', '/dashboard'));
    }

    public function resend(Request $request, string $token): RedirectResponse
    {
        $claim = $this->findClaim($token);

        if ($claim === null) {
            abort(404);
        }

        $user = $claim->user()->first();

        if (WorkspaceClaim::isClaimed($user)) {
            return redirect()->route('login')->with('status', __('This account is already set up — please sign in.'));
        }

        $limiterKey = "platforms:claim-resend:{$user->getKey()}";

        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            return back()->with('status', __('If this account still needs setup, a new link is on its way.'));
        }

        RateLimiter::hit($limiterKey, 3600);

        // The fresh token goes ONLY to the owner's inbox. Never redirect the
        // browser to it: whoever holds an old/expired link (forwarded email,
        // shared screen) must not be able to mint a working claim URL.
        $this->claims->issueAndSend($user, $claim->connection, $request->ip());

        return back()->with('status', __('If this account still needs setup, a new link is on its way.'));
    }

    private function findClaim(string $token): ?WorkspaceClaim
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return WorkspaceClaim::query()
            ->where('token_hash', WorkspaceClaim::hashToken($token))
            ->with(['user', 'workspace', 'connection.platform', 'connection.storeSettings'])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WorkspaceClaim $claim, string $rawToken): array
    {
        $user = $claim->user;
        $connection = $claim->connection;
        $settings = $connection?->storeSettings;

        return [
            'workspace' => ['name' => $claim->workspace?->name],
            'store' => [
                'name' => $settings?->store_name ?? $claim->workspace?->name,
                'domain' => $settings?->store_domain,
            ],
            'platform' => [
                'name' => $connection?->platform?->name,
                'logo' => $connection?->platform?->driver,
            ],
            'owner' => [
                'name' => $settings?->owner_name ?? $user?->name,
                'email' => $user?->getAttribute('email'),
                'phone' => $settings?->owner_phone ?? $user?->getAttribute('phone'),
            ],
            'maskedEmail' => ClaimService::maskEmail($user?->getAttribute('email')),
            // The raw token is already in the address bar; the form
            // needs it to build the POST URL.
            'token' => $rawToken,
        ];
    }
}
