<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\City;
use App\Models\District;
use App\Models\UserAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUserAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'address_label' => ['sometimes', 'string', 'max:50'],
            'province_id' => ['sometimes', 'string', 'size:2', 'exists:provinces,id'],
            'city_id' => ['sometimes', 'string', 'size:4', 'exists:cities,id'],
            'district_id' => ['sometimes', 'string', 'size:7', 'exists:districts,id'],
            'subdistrict' => ['nullable', 'string', 'max:100'],
            'address_detail' => ['sometimes', 'string', 'max:1000'],
            'zip_code' => ['nullable', 'string', 'max:5', 'regex:/^\\d{5}$/'],
            'recipient_name' => ['sometimes', 'string', 'max:100'],
            'recipient_phone' => ['sometimes', 'string', 'max:20'],
            'location_note' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('address_label')) {
            $this->merge(['address_label' => trim((string) $this->input('address_label', ''))]);
        }

        if ($this->has('province_id')) {
            $value = trim((string) $this->input('province_id', ''));
            $this->merge(['province_id' => $value !== '' ? str_pad($value, 2, '0', STR_PAD_LEFT) : '']);
        }

        if ($this->has('city_id')) {
            $value = trim((string) $this->input('city_id', ''));
            $this->merge(['city_id' => $value !== '' ? str_pad($value, 4, '0', STR_PAD_LEFT) : '']);
        }

        if ($this->has('district_id')) {
            $value = trim((string) $this->input('district_id', ''));
            $this->merge(['district_id' => $value !== '' ? str_pad($value, 7, '0', STR_PAD_LEFT) : '']);
        }

        if ($this->has('subdistrict')) {
            $this->merge(['subdistrict' => trim((string) $this->input('subdistrict', ''))]);
        }

        if ($this->has('address_detail')) {
            $this->merge(['address_detail' => trim((string) $this->input('address_detail', ''))]);
        }

        if ($this->has('zip_code')) {
            $this->merge(['zip_code' => $this->normalizeZipCode($this->input('zip_code'))]);
        }

        if ($this->has('recipient_name')) {
            $this->merge(['recipient_name' => trim((string) $this->input('recipient_name', ''))]);
        }

        if ($this->has('recipient_phone')) {
            $this->merge(['recipient_phone' => trim((string) $this->input('recipient_phone', ''))]);
        }

        if ($this->has('location_note')) {
            $this->merge(['location_note' => trim((string) $this->input('location_note', ''))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $addressId = trim((string) $this->route('addressId', ''));
            $existing = $addressId !== '' ? UserAddress::query()->find($addressId) : null;

            $provinceId = $this->filled('province_id')
                ? (string) $this->input('province_id')
                : (string) ($existing?->province_id ?? '');
            $cityId = $this->filled('city_id')
                ? (string) $this->input('city_id')
                : (string) ($existing?->city_id ?? '');
            $districtId = $this->filled('district_id')
                ? (string) $this->input('district_id')
                : (string) ($existing?->district_id ?? '');

            if ($cityId !== '' && $provinceId !== '') {
                $city = City::query()->find($cityId);
                if ($city && $city->province_id !== $provinceId) {
                    $validator->errors()->add('city_id', 'Kota/Kabupaten tidak sesuai dengan provinsi yang dipilih.');
                }
            }

            if ($districtId !== '' && $cityId !== '') {
                $district = District::query()->find($districtId);
                if ($district && $district->city_id !== $cityId) {
                    $validator->errors()->add('district_id', 'Kecamatan tidak sesuai dengan kota/kabupaten yang dipilih.');
                }
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
