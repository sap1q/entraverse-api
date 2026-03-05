<?php

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new NoHtml],
            'brand' => ['required', 'string', 'max:255', new NoHtml],
            'category' => ['required', 'string', 'max:255', new NoHtml],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'spu' => ['nullable', 'string', 'max:50', new NoHtml],
            'trade_in' => ['nullable', 'boolean'],
            'product_status' => ['nullable', 'in:active,pending_approval,inactive,archived'],

            'inventory' => ['nullable', 'array'],
            'inventory.price' => ['nullable', 'numeric', 'min:0'],
            'inventory.total_stock' => ['nullable', 'integer', 'min:0'],
            'inventory.weight' => ['nullable', 'integer', 'min:0'],
            'inventory.dimensions_cm' => ['nullable', 'array'],
            'inventory.dimensions_cm.length' => ['nullable', 'numeric', 'min:0'],
            'inventory.dimensions_cm.width' => ['nullable', 'numeric', 'min:0'],
            'inventory.dimensions_cm.height' => ['nullable', 'numeric', 'min:0'],
            'inventory.volume_m3' => ['nullable', 'numeric', 'min:0'],

            'variants' => ['nullable', 'array'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255', new NoHtml],
            'variants.*.options' => ['nullable', 'array'],

            'variant_pricing' => ['nullable', 'array'],
            'variant_pricing.*.sku' => ['nullable', 'string', 'max:100', new NoHtml],
            'variant_pricing.*.label' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.options' => ['nullable', 'array'],
            'variant_pricing.*.options.*' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.warehouse' => ['nullable', 'string', 'max:120', new NoHtml],
            'variant_pricing.*.warehouse_stock' => ['nullable', 'array'],
            'variant_pricing.*.warehouse_stock.*' => ['nullable', 'integer', 'min:0'],
            'variant_pricing.*.stock' => ['nullable', 'integer', 'min:0'],
            'variant_pricing.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.purchase_price_idr' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.currency' => ['nullable', 'in:SGD,USD,AUD,EUR,IDR,CNY'],
            'variant_pricing.*.exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.exchange_value' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shipping' => ['nullable', 'in:Udara,Laut,Darat'],
            'variant_pricing.*.shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.arrival_cost' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.offline_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.entraverse_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tokopedia_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tokopedia_fee' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tiktok_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.tiktok_fee' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shopee_price' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.shopee_fee' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.sku_seller' => ['nullable', 'string', 'max:100', new NoHtml],
            'variant_pricing.*.item_weight' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.avg_sales_a' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.stockout_date_a' => ['nullable', 'date'],
            'variant_pricing.*.stockout_factor_a' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.avg_sales_b' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.stockout_date_b' => ['nullable', 'date'],
            'variant_pricing.*.stockout_factor_b' => ['nullable', 'string', 'max:255', new NoHtml],
            'variant_pricing.*.avg_daily_final' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.start_date' => ['nullable', 'date'],
            'variant_pricing.*.predicted_initial_stock' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.lead_time' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.reorder_point' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.need_15_days' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.in_transit_stock' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.next_procurement' => ['nullable', 'numeric', 'min:0'],
            'variant_pricing.*.status' => ['nullable', 'in:Normal,Low Stock,Out of Stock'],

            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['string', 'max:2048'],

            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Backward compatibility fields.
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'integer', 'min:0'],
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
