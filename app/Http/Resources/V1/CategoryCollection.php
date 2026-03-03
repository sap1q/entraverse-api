<?php

// app/Http/Resources/V1/CategoryCollection.php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CategoryCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => CategoryResource::collection($this->collection),
            'meta' => [
                'total' => $this->collection->count(),
                'timestamp' => now()->toISOString()
            ]
        ];
    }
}