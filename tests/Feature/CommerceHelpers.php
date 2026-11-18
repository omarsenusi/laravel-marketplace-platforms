<?php

require_once __DIR__.'/Phase3Helpers.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

function commerceConnection(string $storeId): PlatformConnection
{
    Bus::fake();
    phase3FakeSalla($storeId);
    $platform = phase3SallaPlatform();

    phase3RunDelivery($platform, 'app.store.authorize', phase3SallaAuthorizePayload($storeId));

    return phase3ActiveConnection($platform, $storeId);
}

/**
 * Swap in a fresh Http factory (Http::fake merges, so re-faking the
 * same host needs a swap first — see phase3Refake).
 *
 * @param  array<string, mixed>  $stubs
 */
function commerceRefake(array $stubs): void
{
    phase3Refake($stubs);
}

function commerceTokens(): array
{
    return [
        'access_token' => 'salla-access-new',
        'refresh_token' => 'salla-refresh-new',
        'expires' => time() + 7200,
        'scope' => 'offline_access',
    ];
}

function commerceSallaOk(mixed $data, int $status = 200, array $headers = []): mixed
{
    return Http::response(['status' => $status, 'success' => true, 'data' => $data], $status, $headers);
}

function commerceSallaError(int $status, string $code, ?array $fields = null): mixed
{
    $error = array_filter(['code' => $code, 'message' => 'failed', 'fields' => $fields], static fn ($v): bool => $v !== null);

    return Http::response(['status' => $status, 'success' => false, 'error' => $error], $status);
}
