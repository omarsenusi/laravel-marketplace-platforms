<?php

use Illuminate\Support\Facades\Route;
use Omarsenusi\MarketplacePlatforms\Http\Controllers\WebhookIngressController;

/*
|--------------------------------------------------------------------------
| Public inbound-webhook ingress (no session, no CSRF).
|--------------------------------------------------------------------------
|
| Deliberately loaded WITHOUT the 'web' middleware group: marketplace
| servers (Salla / Zid) post here with no session cookie and no CSRF
| token. Throttle is configurable via platforms.webhooks.throttle.
|
*/

Route::post('/webhooks/platforms/{platformSlug}/{endpointKey}', WebhookIngressController::class)
    ->name('platforms.webhooks.ingress')
    ->middleware('throttle:'.config('platforms.webhooks.throttle', '600,1'));
