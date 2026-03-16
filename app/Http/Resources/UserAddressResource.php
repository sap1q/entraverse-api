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
        $provinceName = $this->province?->name;
        $cityName = $this->city
            ? trim(($this->city->type ? "{$this->city->type} " : '') . $this->city->name)
            : null;
        $districtName = $this->district?->name;
        $subdistrictName = $this->subdistrict;

        $segments = array_filter([
            $this->address_detail,
            $subdistrictName,
            $districtName,
            $cityName,
            $provinceName,
            $this->zip_code,
        ]);

        return [
            'id' => $this->id,
            'address_label' => $this->address_label,
            'label' => $this->address_label,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'phone_number' => $this->recipient_phone,
            'address_detail' => $this->address_detail,
            'address_line' => $this->address_detail,
            'province_id' => $this->province_id,
            'city_id' => $this->city_id,
            'district_id' => $this->district_id,
            'province' => $provinceName,
            'city' => $cityName,
            'district' => $districtName,
            'subdistrict' => $subdistrictName,
            'kelurahan' => $subdistrictName,
            'zip_code' => $this->zip_code,
            'postal_code' => $this->zip_code,
            'location_note' => $this->location_note,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'full_address' => implode(', ', $segments),
            'is_default' => (bool) $this->is_default,
            'is_main' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
