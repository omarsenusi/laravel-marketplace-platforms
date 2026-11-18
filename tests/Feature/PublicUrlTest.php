<?php

require_once __DIR__.'/Phase3Helpers.php';

use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;

/**
 * Behind a tunnel the request reaches Laravel as http://<local-host>
 * while the public site is https://<APP_URL>. Outward-facing links
 * must never follow the request's scheme/host.
 */
test('public urls are built on APP_URL, not the request host', function () {
    config(['app.url' => 'https://shop.example.dev/']);

    expect(PublicUrl::to('/webhooks/platforms/salla/abc'))
        ->toBe('https://shop.example.dev/webhooks/platforms/salla/abc')
        ->and(PublicUrl::route('login'))->toBe('https://shop.example.dev/login');
});
