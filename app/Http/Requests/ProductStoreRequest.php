<?php

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', new NoHtml],
            'brand' => ['required', 'string', 'max:255', new NoHtml],
            'category' => ['nullable', 'string', 'max:255', new NoHtml],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:10000000'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'trade_in' => ['nullable', 'boolean'],
            'product_status' => ['nullable', 'in:active,inactive,draft'],
            'variants' => ['nullable', 'array'],
            'variant_pricing' => ['nullable', 'array'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
