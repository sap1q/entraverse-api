<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Validation\Rule;

class UpdateWarrantyRequest extends StoreWarrantyRequest
{
    public function rules(): array
    {
        $warrantyId = (string) $this->route('warranty');

        return [
            'customer_name' => ['required', 'string', 'max:255', new NoHtml],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'invoice_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('warranties')
                    ->ignore($warrantyId)
                    ->where(fn ($query) => $query->where('serial_number', strtoupper(trim((string) $this->input('serial_number'))))),
            ],
            'serial_number' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
        ];
    }
}
