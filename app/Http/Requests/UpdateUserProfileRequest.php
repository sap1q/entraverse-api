<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^\d+$/', 'max:20'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'address' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.min' => 'Nama lengkap minimal 3 karakter.',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka.',
            'gender.in' => 'Jenis kelamin yang dipilih tidak valid.',
            'date_of_birth.before' => 'Tanggal lahir harus sebelum hari ini.',
            'avatar.image' => 'File foto profil harus berupa gambar.',
            'avatar.mimes' => 'Foto profil harus berformat JPG atau PNG.',
            'avatar.max' => 'Ukuran foto profil maksimal 2 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'phone' => $this->normalizeNullableString('phone'),
            'gender' => $this->normalizeNullableString('gender'),
            'address' => $this->normalizeNullableString('address'),
            'country' => $this->normalizeNullableString('country'),
            'date_of_birth' => $this->normalizeNullableString('date_of_birth'),
        ]);
    }

    private function normalizeNullableString(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
