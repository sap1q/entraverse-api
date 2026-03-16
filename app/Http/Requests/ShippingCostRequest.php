<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'city_id' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'weight' => ['required', 'integer', 'min:1', 'max:1000000'],
            'courier' => ['required', 'string', 'max:50'],
        ];
    }
}
