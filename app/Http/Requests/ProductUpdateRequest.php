<?php

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;

class ProductUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', new NoHtml],
            'brand' => ['sometimes', 'required', 'string', 'max:255', new NoHtml],
            'category' => ['sometimes', 'nullable', 'string', 'max:255', new NoHtml],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999.99'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'trade_in' => ['sometimes', 'nullable', 'boolean'],
            'product_status' => ['sometimes', 'nullable', 'in:active,inactive,draft'],
            'variants' => ['sometimes', 'nullable', 'array'],
            'variant_pricing' => ['sometimes', 'nullable', 'array'],
            'images' => ['sometimes', 'nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
