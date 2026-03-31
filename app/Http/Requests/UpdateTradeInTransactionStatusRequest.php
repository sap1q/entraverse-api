<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTradeInTransactionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    'menunggu_review',
                    'disetujui',
                    'ditolak',
                    'menunggu_pengiriman',
                    'dikirim_pelanggan',
                    'kunjungan_toko',
                    'selesai',
                    'dibatalkan',
                ]),
            ],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
