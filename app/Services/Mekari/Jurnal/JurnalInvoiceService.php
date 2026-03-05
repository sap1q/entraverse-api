<?php

declare(strict_types=1);

namespace App\Services\Mekari\Jurnal;

use App\Services\Mekari\MekariService;

class JurnalInvoiceService
{
    public function __construct(protected readonly MekariService $mekari) {}

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $params
     * @return array<string, mixed>
     */
    public function getInvoices(array $params = []): array
    {
        return $this->mekari->request('GET', "{$this->jurnalBasePath()}/invoices", [
            'query' => $params,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        return $this->mekari->request('POST', "{$this->jurnalBasePath()}/invoices", [
            'body' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateInvoice(string $invoiceId, array $payload): array
    {
        return $this->mekari->request('PUT', "{$this->jurnalBasePath()}/invoices/{$invoiceId}", [
            'body' => $payload,
        ]);
    }

    protected function jurnalBasePath(): string
    {
        return rtrim((string) config('services.mekari.jurnal_base_path', '/public/jurnal/api/v1'), '/');
    }
}
