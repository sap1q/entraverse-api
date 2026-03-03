<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final class WarrantyCalculator
{
    public function calculateCost(float $price, float $costPercent): float
    {
        return $this->nonNegative($price) * ($this->nonNegative($costPercent) / 100);
    }

    public function calculateProfit(float $costValue, float $profitPercent): float
    {
        return $this->nonNegative($costValue) * ($this->nonNegative($profitPercent) / 100);
    }

    private function nonNegative(float $value): float
    {
        return max(0, $value);
    }
}
