<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;
use Omarsenusi\MarketplacePlatforms\Api\ApiResult;
use Omarsenusi\MarketplacePlatforms\Api\StoreApiGateway;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;

/**
 * Salla instant settlements (base https://api.salla.dev/admin/v2).
 */
final class SallaSettlementGateway implements SettlementGateway
{
    public function __construct(private readonly StoreApiGateway $gateway) {}

    public function create(PlatformConnection $connection, int $referenceId, Money $amount): CommerceResult
    {
        $result = $this->gateway->post($connection, '/settlements/instant', [
            'reference_id' => $referenceId,
            'amount' => (float) SallaMoney::toDecimal($amount->amountMinor),
        ]);

        if ($result->status === 403) {
            return self::forbidden($result);
        }

        return CommerceResult::fromApiResult($result, fn ($data): Settlement => self::settlement($data, $amount->currency));
    }

    public function update(
        PlatformConnection $connection,
        string $settlementId,
        ?Money $amount = null,
        ?SettlementStatus $status = null,
    ): CommerceResult {
        if ($status === SettlementStatus::Settled || $status === SettlementStatus::Canceled) {
            throw new InvalidArgumentException('Only pending|ready may be set on a settlement update.');
        }

        if ($amount === null && $status === null) {
            throw new InvalidArgumentException('Settlement update requires an amount, a status, or both.');
        }

        $result = $this->gateway->put(
            $connection,
            '/settlements/instant/'.rawurlencode($settlementId),
            array_filter([
                'amount' => $amount === null ? null : (float) SallaMoney::toDecimal($amount->amountMinor),
                'status' => $status?->value,
            ], static fn ($v): bool => $v !== null),
        );

        if ($result->status === 403) {
            return self::forbidden($result);
        }

        return CommerceResult::fromApiResult(
            $result,
            fn ($data): Settlement => self::settlement($data, $amount?->currency),
        );
    }

    public function find(PlatformConnection $connection, string $settlementId): CommerceResult
    {
        $result = $this->gateway->get($connection, '/settlements/instant/'.rawurlencode($settlementId));

        if ($result->status === 403) {
            return self::forbidden($result);
        }

        return CommerceResult::fromApiResult($result, fn ($data): Settlement => self::settlement($data));
    }

    public function list(PlatformConnection $connection, ?int $referenceId = null, int $page = 1): CommerceResult
    {
        $result = $this->gateway->get($connection, '/settlements/instant', array_filter([
            'reference_id' => $referenceId,
            'page' => max(1, $page),
        ], static fn ($v): bool => $v !== null));

        if ($result->status === 403) {
            return self::forbidden($result);
        }

        return CommerceResult::fromApiResult($result, function ($data, $raw = null): SettlementListPage {
            $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];

            return new SettlementListPage(
                settlements: array_values(array_map(
                    static fn ($row): Settlement => self::settlement($row),
                    array_filter($rows, 'is_array'),
                )),
                nextPage: self::nextPage($raw),
            );
        });
    }

    public function delete(PlatformConnection $connection, string $settlementId): CommerceResult
    {
        $result = $this->gateway->delete($connection, '/settlements/instant/'.rawurlencode($settlementId));

        if ($result->status === 403) {
            return self::forbidden($result);
        }

        return CommerceResult::fromApiResult($result, fn (): bool => true);
    }

    private static function settlement(mixed $data, ?string $currency = null): Settlement
    {
        $row = is_array($data) ? $data : [];

        $status = isset($row['status']) && is_string($row['status'])
            ? SettlementStatus::tryFrom($row['status'])
            : null;

        if ($status === null) {
            throw new InvalidArgumentException('Settlement response carries no known status.');
        }

        $amount = isset($row['amount']) && is_numeric($row['amount'])
            ? (int) round((float) $row['amount'] * 100)
            : 0;

        return new Settlement(
            id: (string) ($row['id'] ?? ''),
            referenceId: isset($row['reference_id']) && is_numeric($row['reference_id'])
                ? (int) $row['reference_id']
                : 0,
            amountMinor: $amount,
            currency: $currency,
            status: $status,
            raw: $row,
        );
    }

    private static function forbidden(ApiResult $result): CommerceResult
    {
        return CommerceResult::fail(
            403,
            'settlements_forbidden',
            $result->errorCode ?? 'forbidden',
            $result->fields,
            is_array($result->json) ? $result->json : null,
            $result->rateLimitRemaining,
            $result->retryAfterHeader,
        );
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function nextPage(?array $body): ?int
    {
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $current = $pagination['currentPage'] ?? $pagination['current_page'] ?? null;
        $total = $pagination['totalPages'] ?? $pagination['total_pages'] ?? null;

        if (is_numeric($current) && is_numeric($total) && (int) $current < (int) $total) {
            return (int) $current + 1;
        }

        return null;
    }
}
