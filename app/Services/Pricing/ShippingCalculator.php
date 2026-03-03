<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final class ShippingCalculator
{
    public function calculateAir(float $weightKg, float $ratePerKg): float
    {
        return $this->nonNegative($weightKg) * $this->nonNegative($ratePerKg);
    }

    public function calculateSea(float $volumeCbm, float $ratePerCbm): float
    {
        return $this->nonNegative($volumeCbm) * $this->nonNegative($ratePerCbm);
    }

    private function nonNegative(float $value): float
    {
        return max(0, $value);
    }
}
