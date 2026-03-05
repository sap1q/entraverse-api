<?php

declare(strict_types=1);

namespace App\Services\Mekari\Jurnal;

use App\Services\Mekari\MekariService;

class JurnalCustomerService
{
    public function __construct(protected readonly MekariService $mekari) {}

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $params
     * @return array<string, mixed>
     */
    public function getCustomers(array $params = []): array
    {
        return $this->mekari->request('GET', "{$this->jurnalBasePath()}/contacts", [
            'query' => $params,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array
    {
        return $this->mekari->request('POST', "{$this->jurnalBasePath()}/contacts", [
            'body' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCustomer(string $customerId, array $payload): array
    {
        return $this->mekari->request('PUT', "{$this->jurnalBasePath()}/contacts/{$customerId}", [
            'body' => $payload,
        ]);
    }

    protected function jurnalBasePath(): string
    {
        return rtrim((string) config('services.mekari.jurnal_base_path', '/public/jurnal/api/v1'), '/');
    }
}
