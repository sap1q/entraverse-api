<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesOrderFulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['confirm', 'ship', 'cancel'])],
            'tracking_number' => ['nullable', 'required_if:action,ship', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url', 'max:500'],
            'shipping_courier' => ['nullable', 'string', 'max:60'],
            'shipping_service' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
