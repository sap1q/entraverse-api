<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $imageUrl = $this->image_url;

        if (! $imageUrl && $this->image_path) {
            $imageUrl = url('/storage/' . ltrim((string) $this->image_path, '/'));
        }

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'alt_text' => $this->alt_text,
            'image_path' => $this->image_path,
            'image_url' => $imageUrl,
            'link_url' => $this->link_url,
            'order' => (int) $this->order,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
            'deleted_at' => optional($this->deleted_at)?->toISOString(),
        ];
    }
}
