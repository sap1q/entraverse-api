<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'string', 'uuid'],
            'courier' => ['nullable', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'trade_in_discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.variant_sku' => ['nullable', 'string', 'max:120'],
            'items.*.variants' => ['nullable', 'array'],
            'items.*.trade_in_enabled' => ['nullable', 'boolean'],
            'items.*.trade_in_transaction_id' => ['nullable', 'uuid', 'exists:trade_in_transactions,id'],
        ];
    }
}
