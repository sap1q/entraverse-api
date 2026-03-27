<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MidtransService
{
    public function __construct(
        private readonly HttpFactory $http
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{token: string, redirect_url: string|null, raw: array<string, mixed>}
     */
    public function createSnapToken(array $payload): array
    {
        $serverKey = $this->serverKey();
        $timeout = (int) config('services.midtrans.timeout', 30);
        $snapBaseUrl = $this->snapBaseUrl();

        if ($serverKey === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        try {
            $response = $this->http
                ->withBasicAuth($serverKey, '')
                ->acceptJson()
                ->timeout($timeout)
                ->post("{$snapBaseUrl}/transactions", $payload);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Tidak bisa terhubung ke Midtrans Snap.', previous: $throwable);
        }

        if (! $response->successful()) {
            $errorMessage = $this->extractErrorMessage($response->json());
            throw new RuntimeException("Gagal membuat Snap token. {$errorMessage}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Respons Midtrans Snap tidak valid.');
        }

        $token = trim((string) Arr::get($json, 'token', ''));
        if ($token === '') {
            throw new RuntimeException('Snap token tidak ditemukan pada respons Midtrans.');
        }

        return [
            'token' => $token,
            'redirect_url' => Arr::get($json, 'redirect_url'),
            'raw' => $json,
        ];
    }

    public function verifyCallbackSignature(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signatureKey
    ): bool {
        $serverKey = $this->serverKey();
        if ($serverKey === '' || trim($signatureKey) === '') {
            return false;
        }

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        return hash_equals($expected, $signatureKey);
    }

    public function clientKey(): string
    {
        return (string) config('services.midtrans.client_key', '');
    }

    public function isProduction(): bool
    {
        return filter_var(config('services.midtrans.is_production', false), FILTER_VALIDATE_BOOL);
    }

    public function snapJsUrl(): string
    {
        return $this->isProduction()
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionStatus(string $orderId): array
    {
        $serverKey = $this->serverKey();
        $timeout = (int) config('services.midtrans.timeout', 30);
        $apiBaseUrl = $this->apiBaseUrl();
        $normalizedOrderId = trim($orderId);

        if ($serverKey === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        if ($normalizedOrderId === '') {
            throw new RuntimeException('Order ID Midtrans tidak valid untuk sinkronisasi status.');
        }

        try {
            $response = $this->http
                ->withBasicAuth($serverKey, '')
                ->acceptJson()
                ->timeout($timeout)
                ->get(sprintf('%s/%s/status', $apiBaseUrl, rawurlencode($normalizedOrderId)));
        } catch (Throwable $throwable) {
            throw new RuntimeException('Tidak bisa mengambil status transaksi dari Midtrans.', previous: $throwable);
        }

        if (! $response->successful()) {
            $errorMessage = $this->extractErrorMessage($response->json());
            throw new RuntimeException("Gagal mengambil status transaksi Midtrans. {$errorMessage}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Respons status transaksi Midtrans tidak valid.');
        }

        return $json;
    }

    private function serverKey(): string
    {
        return (string) config('services.midtrans.server_key', '');
    }

    private function snapBaseUrl(): string
    {
        $configured = trim((string) config('services.midtrans.snap_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->isProduction()
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }

    private function apiBaseUrl(): string
    {
        $configured = trim((string) config('services.midtrans.api_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->isProduction()
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractErrorMessage(?array $payload): string
    {
        if (! is_array($payload)) {
            return 'Midtrans mengembalikan error tanpa detail.';
        }

        $message = Arr::get($payload, 'error_messages.0')
            ?? Arr::get($payload, 'status_message')
            ?? Arr::get($payload, 'message')
            ?? 'Midtrans mengembalikan error.';

        return trim(Str::of((string) $message)->squish()->value());
    }
}
