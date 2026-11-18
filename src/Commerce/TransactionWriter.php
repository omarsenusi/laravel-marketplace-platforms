<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Transaction writes plus targeted reads (Salla Admin API).
 *
 * PUT /transactions/{transaction_id} supports refund|void|reverse with
 * an optional partial amount (verified against
 * https://docs.salla.dev/transactions/update.md); transactions for an
 * order come from GET /transactions?order_id=
 * (https://docs.salla.dev/transactions/list.md). The list payload
 * carries no status field, so only verified fields are mapped and the
 * rest stays in Transaction::$raw.
 */
interface TransactionWriter
{
    /**
     * @return CommerceResult<TransactionUpdate>
     */
    public function update(
        PlatformConnection $connection,
        string $transactionId,
        TransactionAction $action,
        ?Money $amount = null,
    ): CommerceResult;

    /**
     * @return CommerceResult<list<Transaction>>
     */
    public function listForOrder(PlatformConnection $connection, int $orderId): CommerceResult;
}
