<?php

// app/Http/Resources/V1/CategoryResource.php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_margin' => (float) $this->min_margin,
            'program_garansi' => $this->program_garansi,
            'fees' => $this->fees,
            
            // 🔥 ICON DATA - PENTING UNTUK FRONTEND
            'icon_url' => $this->icon_url,
            'icon_svg' => $this->icon_svg,
            
            // Optional: format icon yang lebih terstruktur
            'icon' => [
                'url' => $this->icon_url,
                'svg' => $this->icon_svg,
                'type' => $this->icon_url ? 'file' : ($this->icon_svg ? 'raw' : null),
            ],
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}