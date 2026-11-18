<?php

namespace Omarsenusi\MarketplacePlatforms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omarsenusi\MarketplacePlatforms\Enums\PlatformStatus;
use Omarsenusi\MarketplacePlatforms\Models\Platform;
use Omarsenusi\MarketplacePlatforms\Models\PlatformWebhookEndpoint;
use Omarsenusi\MarketplacePlatforms\Services\WebhookIngress;

/**
 * Public inbound-webhook ingress. Responds fast and never runs business
 * logic — verification + persistence only, processing is queued.
 */
class WebhookIngressController
{
    public function __construct(
        private readonly WebhookIngress $ingress,
    ) {}

    public function __invoke(Request $request, string $platformSlug, string $endpointKey): JsonResponse
    {
        $platform = Platform::query()->where('slug', $platformSlug)->first();

        if ($platform === null || $platform->status === PlatformStatus::Archived) {
            return response()->json(['ok' => false], 404);
        }

        $endpoint = PlatformWebhookEndpoint::query()
            ->where('platform_id', $platform->id)
            ->where('key', $endpointKey)
            ->where('enabled', true)
            ->first();

        if ($endpoint === null) {
            return response()->json(['ok' => false], 404);
        }

        $result = $this->ingress->handle(
            $platform,
            $endpoint,
            (string) $request->getContent(),
            $this->flatHeaders($request),
        );

        return response()->json($result['body'], $result['http']);
    }

    /**
     * @return array<string, string>
     */
    private function flatHeaders(Request $request): array
    {
        $flat = [];

        foreach ($request->headers->all() as $name => $values) {
            $flat[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $flat;
    }
}
