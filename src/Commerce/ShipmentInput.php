<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

/**
 * New-shipment payload for POST /shipments.
 */
final readonly class ShipmentInput
{
    /**
     * @param  list<ShipmentPackage>  $packages
     */
    public function __construct(
        public int $orderId,
        public int $courierId,
        public ShipmentType $type,
        public ShipmentPaymentMethod $paymentMethod,
        public array $packages,
        public ShipFromBranch|ShipFromAddress $shipFrom,
        public NationalAddress $shipTo,
        public ?Money $cashOnDelivery = null,
        public ?string $externalCompanyName = null,
        public ?bool $overwriteExistsPending = null,
        public ?string $description = null,
        public ?string $remarks = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        return array_filter([
            'order_id' => $this->orderId,
            'courier_id' => $this->courierId,
            'shipment_type' => $this->type->value,
            'payment_method' => $this->paymentMethod->value,
            'cash_on_delivery' => $this->cashOnDelivery === null
                ? null
                : [
                    'amount' => (float) SallaMoney::toDecimal($this->cashOnDelivery->amountMinor),
                    'currency' => $this->cashOnDelivery->currency,
                ],
            'packages' => array_map(
                static fn (ShipmentPackage $p): array => $p->toSallaArray(),
                $this->packages,
            ),
            'ship_from' => $this->shipFrom->toSallaArray(),
            'ship_to' => $this->shipTo->toSallaArray(),
            'external_company_name' => $this->externalCompanyName,
            'overwrite_exists_pending' => $this->overwriteExistsPending,
            'description' => $this->description,
            'remarks' => $this->remarks,
        ], static fn ($v): bool => $v !== null);
    }
}
