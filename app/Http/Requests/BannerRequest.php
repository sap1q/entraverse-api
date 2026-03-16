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
            'image' => [$isUpdate ? 'nullable' : 'required', 'image', 'mimes:jpeg,png,jpg,webp', 'dimensions:width=6912,height=3456', 'max:20480'],
            'order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Gambar banner wajib diunggah.',
            'image.image' => 'File banner harus berupa gambar.',
            'image.mimes' => 'Format banner harus jpeg, jpg, png, atau webp.',
            'image.dimensions' => 'Dimensi banner harus tepat 6912x3456 piksel.',
            'image.max' => 'Ukuran file banner maksimal 20 MB.',
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
