<?php

// app/Http/Resources/V1/CategoryResource.php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => [
                'type' => $this->icon_url ? 'file' : ($this->icon_svg ? 'raw' : null),
                'url' => $this->icon_url,
                'svg' => $this->when($this->icon_svg, $this->icon_svg),
                'content' => $this->when($request->get('include_svg'), $this->getSvgContent())
            ],
            'fees' => [
                'marketplace' => $this->getFeeMarketplace(),
                'shopee' => $this->getFeeShopee(),
                'entraverse' => $this->getFeeEntraverse(),
                'tokopedia_tiktok' => $this->getFeeTokopediaTiktok(),
                'totals' => [
                    'marketplace' => $this->calculateTotalFees('marketplace'),
                    'shopee' => $this->calculateTotalFees('shopee'),
                    'entraverse' => $this->calculateTotalFees('entraverse'),
                    'tokopedia_tiktok' => $this->calculateTotalFees('tokopedia_tiktok')
                ]
            ],
            'program_garansi' => $this->program_garansi,
            'min_margin' => (float) $this->min_margin,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}