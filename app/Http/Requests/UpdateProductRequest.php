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
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'spu' => ['sometimes', 'nullable', 'string', 'max:50', new NoHtml],
            'trade_in' => ['sometimes', 'nullable', 'boolean'],
            'product_status' => ['sometimes', 'nullable', 'in:active,pending_approval,inactive,archived'],

            'inventory' => ['sometimes', 'nullable', 'array'],
            'inventory.price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory.total_stock' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'inventory.weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'inventory.dimensions_cm' => ['sometimes', 'nullable', 'array'],
            'inventory.dimensions_cm.length' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory.dimensions_cm.width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory.dimensions_cm.height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventory.volume_m3' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255', new NoHtml],
            'variants.*.options' => ['nullable', 'array'],

            'variant_pricing' => ['sometimes', 'nullable', 'array'],
            'variant_pricing.*.sku' => ['sometimes', 'nullable', 'string', 'max:100', new NoHtml],
            'variant_pricing.*.label' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.options' => ['sometimes', 'nullable', 'array'],
            'variant_pricing.*.options.*' => ['sometimes', 'nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.warehouse' => ['nullable', 'string', 'max:120', new NoHtml],
            'variant_pricing.*.warehouse_stock' => ['nullable', 'array'],
            'variant_pricing.*.warehouse_stock.*' => ['nullable', 'integer', 'min:0'],
            'variant_pricing.*.stock' => ['nullable', 'integer', 'min:0'],
            'variant_pricing.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.purchase_price_idr' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.currency' => ['sometimes', 'nullable', 'in:SGD,USD,AUD,EUR,IDR,CNY'],
            'variant_pricing.*.exchange_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.exchange_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shipping' => ['sometimes', 'nullable', 'in:Udara,Laut,Darat'],
            'variant_pricing.*.shipping_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.arrival_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.offline_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.entraverse_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tokopedia_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tokopedia_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tiktok_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tiktok_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shopee_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shopee_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.sku_seller' => ['sometimes', 'nullable', 'string', 'max:100', new NoHtml],
            'variant_pricing.*.item_weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.avg_sales_a' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.stockout_date_a' => ['sometimes', 'nullable', 'date'],
            'variant_pricing.*.stockout_factor_a' => ['sometimes', 'nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.avg_sales_b' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.stockout_date_b' => ['sometimes', 'nullable', 'date'],
            'variant_pricing.*.stockout_factor_b' => ['sometimes', 'nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.avg_daily_final' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.start_date' => ['sometimes', 'nullable', 'date'],
            'variant_pricing.*.predicted_initial_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.lead_time' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.need_15_days' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.in_transit_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.next_procurement' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'variant_pricing.*.status' => ['sometimes', 'nullable', 'in:Normal,Low Stock,Out of Stock'],

            'photos' => ['sometimes', 'nullable', 'array', 'max:5'],
            'photos.*' => ['string', 'max:2048'],

            'images' => ['sometimes', 'nullable', 'array', 'max:5'],
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
