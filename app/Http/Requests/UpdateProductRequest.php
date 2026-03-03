<?php

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', new NoHtml],
            'brand' => ['sometimes', 'required', 'string', 'max:255', new NoHtml],
            'category' => ['sometimes', 'required', 'string', 'max:255', new NoHtml],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000', new NoHtml],
            'spu' => ['sometimes', 'nullable', 'string', 'max:50', new NoHtml],
            'trade_in' => ['sometimes', 'nullable', 'boolean'],
            'product_status' => ['sometimes', 'nullable', 'in:active,pending_approval,inactive,archived'],

            'inventory' => ['sometimes', 'nullable', 'array'],
            'inventory.price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory.total_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'inventory.weight' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255', new NoHtml],
            'variants.*.options' => ['nullable', 'array'],

            'variant_pricing' => ['sometimes', 'nullable', 'array'],
            'variant_pricing.*.label' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.stock' => ['nullable', 'integer', 'min:0'],
            'variant_pricing.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.purchase_price_idr' => ['nullable', 'numeric', 'min:0'],

            'photos' => ['sometimes', 'nullable', 'array'],
            'photos.*' => ['string', 'max:2048'],

            'images' => ['sometimes', 'nullable', 'array', 'max:8'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Backward compatibility fields.
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $decoded = [];
        foreach (['inventory', 'variants', 'variant_pricing', 'photos'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $json = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded[$field] = $json;
                }
            }
        }

        if ($decoded !== []) {
            $this->merge($decoded);
        }
    }
}
