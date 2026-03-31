<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarrantyLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => ['required', 'string', 'max:80'],
            'serial_number' => ['required', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'invoice_number' => strtoupper(trim((string) $this->input('invoice_number'))),
            'serial_number' => strtoupper(trim((string) $this->input('serial_number'))),
        ]);
    }
}
