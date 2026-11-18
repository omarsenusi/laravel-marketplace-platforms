<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Instant settlements for dropshipping apps (Salla Admin API).
 *
 * Only apps approved by Salla for dropshipping may call these endpoints;
 * otherwise Salla answers 403, which surfaces as a forbidden CommerceResult
 * (CommerceResult::$forbidden, error `settlements_forbidden`) instead of a
 * generic failure. Verified against
 * https://docs.salla.dev/settlements/create.md,
 * https://docs.salla.dev/settlements/update.md,
 * https://docs.salla.dev/settlements/details.md,
 * https://docs.salla.dev/settlements/list.md and
 * https://docs.salla.dev/settlements/delete.md.
 */
interface SettlementGateway
{
    /**
     * Hold funds for an order reference.
     *
     * @return CommerceResult<Settlement>
     */
    public function create(PlatformConnection $connection, int $referenceId, Money $amount): CommerceResult;

    /**
     * Only pending|ready may be set on update; settled|canceled are
     * terminal on Salla's side and rejected here before any HTTP call.
     *
     * @return CommerceResult<Settlement>
     */
    public function update(
        PlatformConnection $connection,
        string $settlementId,
        ?Money $amount = null,
        ?SettlementStatus $status = null,
    ): CommerceResult;

    /**
     * @return CommerceResult<Settlement>
     */
    public function find(PlatformConnection $connection, string $settlementId): CommerceResult;

    /**
     * @return CommerceResult<SettlementListPage>
     */
    public function list(PlatformConnection $connection, ?int $referenceId = null, int $page = 1): CommerceResult;

    /**
     * @return CommerceResult<true>
     */
    public function delete(PlatformConnection $connection, string $settlementId): CommerceResult;
}
