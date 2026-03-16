<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\City;
use App\Models\District;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'address_label' => ['required', 'string', 'max:50'],
            'province_id' => ['required', 'string', 'size:2', 'exists:provinces,id'],
            'city_id' => ['required', 'string', 'size:4', 'exists:cities,id'],
            'district_id' => ['required', 'string', 'size:7', 'exists:districts,id'],
            'subdistrict' => ['nullable', 'string', 'max:100'],
            'address_detail' => ['required', 'string', 'max:1000'],
            'zip_code' => ['nullable', 'string', 'max:5', 'regex:/^\\d{5}$/'],
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'location_note' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $provinceId = trim((string) $this->input('province_id', ''));
        $cityId = trim((string) $this->input('city_id', ''));
        $districtId = trim((string) $this->input('district_id', ''));

        $this->merge([
            'address_label' => trim((string) $this->input('address_label', '')),
            'province_id' => $provinceId !== '' ? str_pad($provinceId, 2, '0', STR_PAD_LEFT) : '',
            'city_id' => $cityId !== '' ? str_pad($cityId, 4, '0', STR_PAD_LEFT) : '',
            'district_id' => $districtId !== '' ? str_pad($districtId, 7, '0', STR_PAD_LEFT) : '',
            'subdistrict' => trim((string) $this->input('subdistrict', '')),
            'address_detail' => trim((string) $this->input('address_detail', '')),
            'zip_code' => $this->normalizeZipCode($this->input('zip_code')),
            'recipient_name' => trim((string) $this->input('recipient_name', '')),
            'recipient_phone' => trim((string) $this->input('recipient_phone', '')),
            'location_note' => trim((string) $this->input('location_note', '')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provinceId = (string) $this->input('province_id', '');
            $cityId = (string) $this->input('city_id', '');
            $districtId = (string) $this->input('district_id', '');

            $city = City::query()->find($cityId);
            if ($city && $city->province_id !== $provinceId) {
                $validator->errors()->add('city_id', 'Kota/Kabupaten tidak sesuai dengan provinsi yang dipilih.');
            }

            $district = District::query()->find($districtId);
            if ($district && $district->city_id !== $cityId) {
                $validator->errors()->add('district_id', 'Kecamatan tidak sesuai dengan kota/kabupaten yang dipilih.');
            }
        });
    }

    private function normalizeZipCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\\D+/', '', (string) $value) ?? '';
        if ($clean === '') {
            return null;
        }

        return str_pad(substr($clean, 0, 5), 5, '0', STR_PAD_LEFT);
    }
}
