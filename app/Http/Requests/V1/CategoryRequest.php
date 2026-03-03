<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Set ke true biar bisa dipake
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'min_margin' => 'required|numeric',
            'program_garansi' => 'nullable|string',
            // Validasi file ikon (PNG/SVG only)
            'image' => 'nullable|file|mimes:svg,png|max:2048', 
            // Validasi JSON Fees (karena dikirim via FormData as String)
            'fees' => 'required', 
        ];
    }

    // Custom pesan error biar admin nggak bingung
    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi, Men!',
            'image.mimes' => 'Ikon harus format SVG atau PNG.',
            'min_margin.numeric' => 'Margin harus berupa angka (pake titik untuk desimal).',
        ];
    }
}
