<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTOs\PriceBreakdown;
use App\Models\Product;

final class PricingCalculator
{
    public function __construct(
        private readonly ShippingCalculator $shippingCalculator,
        private readonly FeeCalculator $feeCalculator,
        private readonly WarrantyCalculator $warrantyCalculator,
    ) {
    }

    public function calculateBasePrice(float $basePrice, float $exchangeRate): float
    {
        return max(0, $basePrice) * max(0, $exchangeRate);
    }

    public function calculateShipping(float $weightKg, float $volumeCbm, float $airRate, float $seaRate): array
    {
        return [
            'air' => $this->shippingCalculator->calculateAir($weightKg, $airRate),
            'sea' => $this->shippingCalculator->calculateSea($volumeCbm, $seaRate),
        ];
    }

    /**
     * @param array<string,float|int> $feeRates
     */
    public function calculateFees(float $price, array $feeRates): float
    {
        return $this->feeCalculator->sumFromRates($price, $feeRates);
    }

    public function calculateWarranty(float $price, float $warrantyCostPercent, float $warrantyProfitPercent): array
    {
        $cost = $this->warrantyCalculator->calculateCost($price, $warrantyCostPercent);
        $profit = $this->warrantyCalculator->calculateProfit($cost, $warrantyProfitPercent);

        return [
            'cost' => $cost,
            'profit' => $profit,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function getPriceBreakdown(array $payload): PriceBreakdown
    {
        $baseAmount = (float) ($payload['base_price'] ?? 0);
        $exchangeRate = (float) ($payload['exchange_rate'] ?? 0);
        $weightKg = (float) ($payload['weight_kg'] ?? 0);
        $volumeCbm = (float) ($payload['volume_cbm'] ?? 0);
        $airRate = (float) ($payload['shipping_air_rate'] ?? 155000);
        $seaRate = (float) ($payload['shipping_sea_rate'] ?? 7500000);
        $marginPercent = (float) ($payload['margin_percent'] ?? 0);
        $shippingNew = (float) ($payload['shipping_new'] ?? 0);

        $commissionRate = (float) ($payload['commission_rate'] ?? 4.0);
        $cashbackRate = (float) ($payload['cashback_rate'] ?? 4.5);
        $insuranceRate = (float) ($payload['insurance_rate'] ?? 0.5);
        $warrantyCostRate = (float) ($payload['warranty_cost_rate'] ?? 3.0);
        $warrantyProfitRate = (float) ($payload['warranty_profit_rate'] ?? 100.0);

        $baseIdr = $this->calculateBasePrice($baseAmount, $exchangeRate);
        $shipping = $this->calculateShipping($weightKg, $volumeCbm, $airRate, $seaRate);

        $commission = $this->feeCalculator->fromRate($baseIdr, $commissionRate);
        $cashback = $this->feeCalculator->fromRate($baseIdr, $cashbackRate);
        $insurance = $this->feeCalculator->fromRate($baseIdr, $insuranceRate);

        $warranty = $this->calculateWarranty($baseIdr, $warrantyCostRate, $warrantyProfitRate);
        $totalCost = $baseIdr
            + $shipping['air']
            + $shipping['sea']
            + $commission
            + $cashback
            + $insurance
            + $warranty['cost']
            + $shippingNew;

        $recommendedPrice = $totalCost + ($totalCost * (max(0, $marginPercent) / 100));
        $tokopediaRate = (float) ($payload['tokopedia_rate'] ?? 0);
        $shopeeRate = (float) ($payload['shopee_rate'] ?? ($commissionRate + $cashbackRate + $insuranceRate));
        $tiktokRate = (float) ($payload['tiktok_rate'] ?? ($commissionRate + $cashbackRate));

        return new PriceBreakdown(
            basePrice: $baseIdr,
            exchangeRate: $exchangeRate,
            shippingAir: $shipping['air'],
            shippingSea: $shipping['sea'],
            commission: $commission,
            cashback: $cashback,
            insurance: $insurance,
            warrantyCost: $warranty['cost'],
            warrantyProfit: $warranty['profit'],
            shippingNew: max(0, $shippingNew),
            totalCost: $totalCost,
            recommendedPrice: $recommendedPrice,
            platformPrices: [
                'entraverse' => $recommendedPrice,
                'tokopedia' => $recommendedPrice + $this->feeCalculator->fromRate($recommendedPrice, $tokopediaRate),
                'shopee' => $recommendedPrice + $this->feeCalculator->fromRate($recommendedPrice, $shopeeRate),
                'tiktok' => $recommendedPrice + $this->feeCalculator->fromRate($recommendedPrice, $tiktokRate),
            ],
        );
    }

    public function fromProduct(Product $product): PriceBreakdown
    {
        $inventory = is_array($product->inventory) ? $product->inventory : [];

        return $this->getPriceBreakdown([
            'base_price' => (float) ($inventory['base_price'] ?? 0),
            'exchange_rate' => (float) ($inventory['exchange_rate'] ?? 0),
            'weight_kg' => (float) ($inventory['weight_kg'] ?? 0),
            'volume_cbm' => (float) ($inventory['volume_cbm'] ?? 0),
            'shipping_air_rate' => (float) ($inventory['shipping_air_rate'] ?? 155000),
            'shipping_sea_rate' => (float) ($inventory['shipping_sea_rate'] ?? 7500000),
            'margin_percent' => (float) ($inventory['margin_percent'] ?? 0),
            'shipping_new' => (float) ($inventory['shipping_new'] ?? 0),
            'commission_rate' => (float) ($inventory['commission_rate'] ?? 4.0),
            'cashback_rate' => (float) ($inventory['cashback_rate'] ?? 4.5),
            'insurance_rate' => (float) ($inventory['insurance_rate'] ?? 0.5),
            'warranty_cost_rate' => (float) ($inventory['warranty_cost_rate'] ?? 3.0),
            'warranty_profit_rate' => (float) ($inventory['warranty_profit_rate'] ?? 100.0),
            'tokopedia_rate' => (float) ($inventory['tokopedia_rate'] ?? 0),
            'shopee_rate' => (float) ($inventory['shopee_rate'] ?? 9.0),
            'tiktok_rate' => (float) ($inventory['tiktok_rate'] ?? 8.5),
        ]);
    }
}
