<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UserAddress */
class UserAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $segments = array_filter([
            $this->address_line,
            $this->city,
            $this->province,
            $this->postal_code,
        ]);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone_number' => $this->phone_number,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'full_address' => implode(', ', $segments),
            'is_main' => (bool) $this->is_main,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}

