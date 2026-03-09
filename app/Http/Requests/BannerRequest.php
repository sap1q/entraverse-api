<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'image' => [$isUpdate ? 'nullable' : 'required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('is_active')) {
            $normalized['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($this->has('order')) {
            $normalized['order'] = (int) $this->input('order');
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
