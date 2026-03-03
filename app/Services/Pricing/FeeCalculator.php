<?php

declare(strict_types=1);

namespace App\Services\Pricing;

final class FeeCalculator
{
    public function fromRate(float $price, float $percent): float
    {
        return $this->nonNegative($price) * ($this->nonNegative($percent) / 100);
    }

    /**
     * @param array<string,float|int> $components
     */
    public function sumFromRates(float $price, array $components): float
    {
        $total = 0.0;
        foreach ($components as $percent) {
            $total += $this->fromRate($price, (float) $percent);
        }

        return $total;
    }

    private function nonNegative(float $value): float
    {
        return max(0, $value);
    }
}
