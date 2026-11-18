<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla shipping setup (base https://api.salla.dev/admin/v2).
 */
final class SallaShippingSetup implements ShippingSetup
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function createCompany(PlatformConnection $connection, string $name): CommerceResult
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Shipping company requires a non-empty name.');
        }

        $result = $this->gateway->post($connection, '/shipping/companies/', ['name' => trim($name)]);

        return CommerceResult::fromApiResult($result, fn ($data): ShippingCompany => self::company($data));
    }

    public function listCompanies(PlatformConnection $connection): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/companies/');

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(
                static fn ($row): ShippingCompany => self::company($row),
                array_filter($rows, 'is_array'),
            ));
        });
    }

    public function findCompany(PlatformConnection $connection, string $companyId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/companies/'.rawurlencode($companyId));

        return CommerceResult::fromApiResult($result, fn ($data): ShippingCompany => self::company($data));
    }

    public function updateCompany(PlatformConnection $connection, string $companyId, string $name): CommerceResult
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Shipping company requires a non-empty name.');
        }

        $result = $this->gateway->put(
            $connection, '/shipping/companies/'.rawurlencode($companyId), ['name' => trim($name)]
        );

        return CommerceResult::fromApiResult($result, fn ($data): ShippingCompany => self::company($data));
    }

    public function deleteCompany(PlatformConnection $connection, string $companyId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/shipping/companies/'.rawurlencode($companyId));

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    public function createZone(PlatformConnection $connection, int $companyId, ZoneInput $input): CommerceResult
    {
        $result = $this->gateway->post($connection, '/shipping/zones', $input->toSallaArray($companyId));

        return CommerceResult::fromApiResult($result, fn ($data): ShippingZone => self::zone($data));
    }

    public function updateZone(PlatformConnection $connection, string $zoneId, int $companyId, ZoneInput $input): CommerceResult
    {
        $result = $this->gateway->put(
            $connection, '/shipping/zones/'.rawurlencode($zoneId), $input->toSallaArray($companyId)
        );

        return CommerceResult::fromApiResult($result, fn ($data): ShippingZone => self::zone($data));
    }

    public function deleteZone(PlatformConnection $connection, string $zoneId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/shipping/zones/'.rawurlencode($zoneId));

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    public function listZones(PlatformConnection $connection, ?int $companyId = null): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/zones', array_filter(
            ['company' => $companyId], static fn ($v): bool => $v !== null
        ));

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(
                static fn ($row): ShippingZone => self::zone($row),
                array_filter($rows, 'is_array'),
            ));
        });
    }

    public function findZone(PlatformConnection $connection, string $zoneId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/zones/'.rawurlencode($zoneId));

        return CommerceResult::fromApiResult($result, fn ($data): ShippingZone => self::zone($data));
    }

    public function createRoute(PlatformConnection $connection, RouteInput $input): CommerceResult
    {
        $result = $this->gateway->post($connection, '/shipping/routes', $input->toSallaArray());

        return CommerceResult::fromApiResult($result, fn ($data): Route => self::route($data));
    }

    public function updateRoute(PlatformConnection $connection, string $routeId, RoutePatch $patch): CommerceResult
    {
        // Route updates use PATCH per https://docs.salla.dev/shipping-routes/update.md.
        $result = $this->gateway->patch(
            $connection, '/shipping/routes/'.rawurlencode($routeId), $patch->toSallaArray()
        );

        return CommerceResult::fromApiResult($result, fn ($data): Route => self::route($data));
    }

    public function deleteRoute(PlatformConnection $connection, string $routeId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/shipping/routes/'.rawurlencode($routeId));

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    public function listRoutes(PlatformConnection $connection, ?string $name = null): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/routes', array_filter(
            ['name' => $name], static fn ($v): bool => $v !== null
        ));

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(
                static fn ($row): Route => self::route($row),
                array_filter($rows, 'is_array'),
            ));
        });
    }

    public function findRoute(PlatformConnection $connection, string $routeId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/shipping/routes/'.rawurlencode($routeId));

        return CommerceResult::fromApiResult($result, fn ($data): Route => self::route($data));
    }

    private static function company(mixed $data): ShippingCompany
    {
        $row = is_array($data) ? $data : [];

        return new ShippingCompany(
            id: (string) ($row['id'] ?? ''),
            name: isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
            slug: isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : null,
            appId: isset($row['app_id']) && is_scalar($row['app_id']) ? (string) $row['app_id'] : null,
            activationType: isset($row['activation_type']) && is_string($row['activation_type'])
                ? $row['activation_type']
                : null,
            raw: $row,
        );
    }

    private static function zone(mixed $data): ShippingZone
    {
        $row = is_array($data) ? $data : [];
        $company = is_array($row['company'] ?? null) ? $row['company'] : [];
        $country = is_array($row['country'] ?? null) ? $row['country'] : [];
        $city = is_array($row['city'] ?? null) ? $row['city'] : [];
        $fees = is_array($row['fees'] ?? null) ? $row['fees'] : [];
        $cod = is_array($row['cash_on_delivery'] ?? null) ? $row['cash_on_delivery'] : [];

        return new ShippingZone(
            id: (string) ($row['id'] ?? ''),
            zoneCode: isset($row['zone_code']) && is_string($row['zone_code']) ? $row['zone_code'] : null,
            companyId: isset($company['id']) && is_scalar($company['id']) ? (string) $company['id'] : null,
            countryId: isset($country['id']) && is_numeric($country['id']) ? (int) $country['id'] : null,
            cityId: isset($city['id']) && is_numeric($city['id']) ? (int) $city['id'] : null,
            fees: $fees === [] ? null : new ZoneFee(
                type: ZoneFeeType::tryFrom((string) ($fees['type'] ?? '')) ?? ZoneFeeType::Fixed,
                amountMinor: isset($fees['amount']) && is_numeric($fees['amount'])
                    ? (int) round((float) $fees['amount'] * 100)
                    : 0,
                upToWeight: isset($fees['up_to_weight']) && is_numeric($fees['up_to_weight'])
                    ? (float) $fees['up_to_weight']
                    : null,
                amountPerUnitMinor: isset($fees['amount_per_unit']) && is_numeric($fees['amount_per_unit'])
                    ? (int) round((float) $fees['amount_per_unit'] * 100)
                    : null,
                perUnit: isset($fees['per_unit']) && is_numeric($fees['per_unit'])
                    ? (float) $fees['per_unit']
                    : null,
            ),
            feesCurrency: isset($fees['currency']) && is_string($fees['currency']) ? $fees['currency'] : null,
            cashOnDelivery: $cod === [] ? null : new ZoneCashOnDelivery(
                status: ($cod['status'] ?? false) === true,
                feesMinor: isset($cod['fees']) && is_numeric($cod['fees'])
                    ? (int) round((float) $cod['fees'] * 100)
                    : 0,
            ),
            duration: isset($row['duration']) && is_string($row['duration']) ? $row['duration'] : null,
            raw: $row,
        );
    }

    private static function route(mixed $data): Route
    {
        $row = is_array($data) ? $data : [];

        return new Route(
            id: (string) ($row['id'] ?? ''),
            name: isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
            type: isset($row['type']) && is_string($row['type']) ? $row['type'] : null,
            status: array_key_exists('status', $row)
                ? ($row['status'] === true || $row['status'] === 1 || $row['status'] === '1')
                : null,
            priority: isset($row['priority']) && is_numeric($row['priority']) ? (int) $row['priority'] : null,
            strategy: isset($row['strategy']) && is_string($row['strategy']) ? $row['strategy'] : null,
            raw: $row,
        );
    }
}
