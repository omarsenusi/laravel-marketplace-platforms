<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla transaction writes + reads (base https://api.salla.dev/admin/v2).
 */
final class SallaTransactionWriter implements TransactionWriter
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function update(
        PlatformConnection $connection,
        string $transactionId,
        TransactionAction $action,
        ?Money $amount = null,
    ): CommerceResult {
        $result = $this->gateway->put(
            $connection,
            '/transactions/'.rawurlencode($transactionId),
            array_filter([
                'action' => $action->value,
                'amount' => $amount === null ? null : (float) SallaMoney::toDecimal($amount->amountMinor),
                'currency' => $amount?->currency,
            ], static fn ($v): bool => $v !== null),
        );

        return CommerceResult::fromApiResult($result, function ($data): TransactionUpdate {
            $row = is_array($data) ? $data : [];

            return new TransactionUpdate(
                message: isset($row['message']) && is_string($row['message']) ? $row['message'] : null,
                raw: $row,
            );
        });
    }

    public function listForOrder(PlatformConnection $connection, int $orderId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/transactions', ['order_id' => $orderId]);

        return CommerceResult::fromApiResult($result, function ($data): array {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return array_values(array_map(static function ($row): Transaction {
                $row = is_array($row) ? $row : [];
                $references = is_array($row['references'] ?? null) ? $row['references'] : [];
                $payment = is_array($row['payment_method'] ?? null) ? $row['payment_method'] : [];

                return new Transaction(
                    id: (string) ($row['id'] ?? ''),
                    orderId: isset($references['order_id']) && is_numeric($references['order_id'])
                        ? (int) $references['order_id']
                        : null,
                    referenceId: isset($references['reference_id']) && is_numeric($references['reference_id'])
                        ? (int) $references['reference_id']
                        : null,
                    total: SallaMoney::fromRow($row, 'total'),
                    paymentProvider: isset($payment['payment_provider']) && is_string($payment['payment_provider'])
                        ? $payment['payment_provider']
                        : null,
                    raw: $row,
                );
            }, array_filter($rows, 'is_array')));
        });
    }
}
