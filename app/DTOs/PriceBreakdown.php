<?php

declare(strict_types=1);

namespace App\DTOs;

final class PriceBreakdown
{
    public function __construct(
        public readonly float $basePrice,
        public readonly float $exchangeRate,
        public readonly float $shippingAir,
        public readonly float $shippingSea,
        public readonly float $commission,
        public readonly float $cashback,
        public readonly float $insurance,
        public readonly float $warrantyCost,
        public readonly float $warrantyProfit,
        public readonly float $shippingNew,
        public readonly float $totalCost,
        public readonly float $recommendedPrice,
        /** @var array<string,float> */
        public readonly array $platformPrices,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'base_price' => $this->basePrice,
            'exchange_rate' => $this->exchangeRate,
            'shipping_air' => $this->shippingAir,
            'shipping_sea' => $this->shippingSea,
            'commission' => $this->commission,
            'cashback' => $this->cashback,
            'insurance' => $this->insurance,
            'warranty_cost' => $this->warrantyCost,
            'warranty_profit' => $this->warrantyProfit,
            'shipping_new' => $this->shippingNew,
            'total_cost' => $this->totalCost,
            'recommended_price' => $this->recommendedPrice,
            'platform_prices' => $this->platformPrices,
        ];
    }
}
