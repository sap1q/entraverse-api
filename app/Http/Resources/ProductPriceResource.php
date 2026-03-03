<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\PriceBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceBreakdown */
class ProductPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PriceBreakdown $resource */
        $resource = $this->resource;

        return $resource->toArray();
    }
}
