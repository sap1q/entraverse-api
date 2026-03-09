<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'in:active,inactive,draft'],
            'is_featured' => ['sometimes', 'required', 'boolean'],
            'stock_status' => ['sometimes', 'required', 'in:in_stock,out_of_stock,preorder'],
        ];
    }
}

