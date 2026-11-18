<?php

use Illuminate\Support\Facades\Route;
use Omarsenusi\MarketplacePlatforms\Http\Controllers\ClaimController;
use Omarsenusi\MarketplacePlatforms\Http\Controllers\LaunchController;
use Omarsenusi\MarketplacePlatforms\Http\Controllers\OAuthCallbackController;
use Omarsenusi\MarketplacePlatforms\Http\Middleware\AllowEmbeddedFrames;

/*
|--------------------------------------------------------------------------
| Marketplace OAuth + claim routes (session needed → 'web' group).
|--------------------------------------------------------------------------
|
| Used by Zid (OAuth) and Salla custom mode (authorization-code flow),
| the "Open app" launch URLs, and the workspace-claim flow. Guest-or-
| auth allowed by design: claim links are bearer secrets delivered by
| email, and the launch endpoint never returns a claim URL.
|
*/

Route::middleware(['web'])->group(function (): void {
    Route::get('/platforms/{slug}/callback', OAuthCallbackController::class)
        ->name('platforms.callback')
        ->middleware('throttle:60,1');

    Route::match(['get', 'post'], '/platforms/{slug}/launch', LaunchController::class)
        ->name('platforms.launch')
        ->middleware([AllowEmbeddedFrames::class, 'throttle:30,1']);

    Route::get('/claim/{token}', [ClaimController::class, 'show'])
        ->name('platforms.claim.show')
        ->middleware('throttle:60,1');

    Route::post('/claim/{token}', [ClaimController::class, 'store'])
        ->name('platforms.claim.store')
        ->middleware('throttle:20,1');

    Route::post('/claim/{token}/resend', [ClaimController::class, 'resend'])
        ->name('platforms.claim.resend')
        ->middleware('throttle:20,1');
});
