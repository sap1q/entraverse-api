<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\NoHtml;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTradeInTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'requested_product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'requested_product_name' => ['nullable', 'string', 'max:255', new NoHtml],
            'requested_product_variant_sku' => ['nullable', 'string', 'max:120'],
            'trade_in_only' => ['nullable', 'boolean'],

            'customer_name' => ['required', 'string', 'max:255', new NoHtml],
            'customer_phone' => ['required', 'string', 'max:40'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_city' => ['nullable', 'string', 'max:120', new NoHtml],
            'customer_address' => ['required', 'string', 'max:2000'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],

            'device_brand' => ['nullable', 'string', 'max:120', new NoHtml],
            'device_model' => ['required', 'string', 'max:160', new NoHtml],
            'device_variant' => ['nullable', 'string', 'max:160', new NoHtml],
            'physical_condition' => ['required', Rule::in(['excellent', 'good', 'fair', 'poor'])],
            'device_age' => ['required', Rule::in(['lt1', '1to2', '2to3', '3to4'])],
            'service_history' => ['required', Rule::in(['never', 'once', 'twice'])],
            'accessory_summary' => ['nullable', 'array'],
            'accessory_summary.*' => ['string', 'max:80'],
            'estimated_amount' => ['required', 'numeric', 'min:0'],

            'photo_slots' => ['required', 'array', 'min:1'],
            'photo_slots.*' => ['required', Rule::in(['front', 'back', 'screen_on', 'damage_detail', 'accessories'])],
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }
}
