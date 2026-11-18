<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Custom shipping setup: companies, zones and routes (Salla Admin API).
 *
 * Routes and branch allocations require Salla's approval for the app;
 * unapproved apps get a 403, which surfaces as CommerceResult::$forbidden.
 * Verified against https://docs.salla.dev/shipping-companies/create.md,
 * .../list.md, .../details.md, .../update.md, .../delete.md,
 * https://docs.salla.dev/shipping-zones/create.md, .../list.md,
 * .../details.md, .../update.md, .../delete.md,
 * https://docs.salla.dev/shipping-routes/create.md, .../list.md,
 * .../details.md, .../update.md and .../delete.md.
 */
interface ShippingSetup
{
    /**
     * @return CommerceResult<ShippingCompany>
     */
    public function createCompany(PlatformConnection $connection, string $name): CommerceResult;

    /**
     * @return CommerceResult<list<ShippingCompany>>
     */
    public function listCompanies(PlatformConnection $connection): CommerceResult;

    /**
     * @return CommerceResult<ShippingCompany>
     */
    public function findCompany(PlatformConnection $connection, string $companyId): CommerceResult;

    /**
     * @return CommerceResult<ShippingCompany>
     */
    public function updateCompany(PlatformConnection $connection, string $companyId, string $name): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function deleteCompany(PlatformConnection $connection, string $companyId): CommerceResult;

    /**
     * @return CommerceResult<ShippingZone>
     */
    public function createZone(PlatformConnection $connection, int $companyId, ZoneInput $input): CommerceResult;

    /**
     * @return CommerceResult<ShippingZone>
     */
    public function updateZone(PlatformConnection $connection, string $zoneId, int $companyId, ZoneInput $input): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function deleteZone(PlatformConnection $connection, string $zoneId): CommerceResult;

    /**
     * @return CommerceResult<list<ShippingZone>>
     */
    public function listZones(PlatformConnection $connection, ?int $companyId = null): CommerceResult;

    /**
     * @return CommerceResult<ShippingZone>
     */
    public function findZone(PlatformConnection $connection, string $zoneId): CommerceResult;

    /**
     * @return CommerceResult<Route>
     */
    public function createRoute(PlatformConnection $connection, RouteInput $input): CommerceResult;

    /**
     * @return CommerceResult<Route>
     */
    public function updateRoute(PlatformConnection $connection, string $routeId, RoutePatch $patch): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function deleteRoute(PlatformConnection $connection, string $routeId): CommerceResult;

    /**
     * @return CommerceResult<list<Route>>
     */
    public function listRoutes(PlatformConnection $connection, ?string $name = null): CommerceResult;

    /**
     * @return CommerceResult<Route>
     */
    public function findRoute(PlatformConnection $connection, string $routeId): CommerceResult;
}
