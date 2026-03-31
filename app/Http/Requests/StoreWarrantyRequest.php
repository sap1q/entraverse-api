<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255', new NoHtml],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'invoice_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('warranties')->where(fn ($query) => $query->where('serial_number', strtoupper(trim((string) $this->input('serial_number'))))),
            ],
            'serial_number' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_name' => trim((string) $this->input('customer_name')),
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'invoice_number' => strtoupper(trim((string) $this->input('invoice_number'))),
            'serial_number' => strtoupper(trim((string) $this->input('serial_number'))),
        ]);
    }
}
