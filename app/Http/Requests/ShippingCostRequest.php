<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShippingCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'string', 'uuid', 'required_without:city_id'],
            'city_id' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/', 'required_without:address_id'],
            'district_id' => ['nullable', 'string', 'size:7', 'regex:/^\d{7}$/'],
            'courier' => ['required', 'string', 'max:50'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000000', 'required_without:items'],
            'items' => ['nullable', 'array', 'min:1', 'required_without:weight'],
            'items.*.product_id' => ['required_with:items', 'string', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1', 'max:1000'],
            'items.*.variant_sku' => ['nullable', 'string', 'max:120'],
            'items.*.variants' => ['nullable', 'array'],
            'items.*.variants.*' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! (bool) config('services.rajaongkir.strict_mode', false)) {
                return;
            }

            $addressId = trim((string) $this->input('address_id', ''));
            $districtId = trim((string) $this->input('district_id', ''));

            if ($addressId === '' && $districtId === '') {
                $validator->errors()->add(
                    'district_id',
                    'Kecamatan tujuan wajib diisi pada strict mode ongkir.'
                );
            }
        });
    }
}
