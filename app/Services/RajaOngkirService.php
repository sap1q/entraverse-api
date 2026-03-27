<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\City;
use App\Models\District;
use App\Models\ShippingRateCache;
use App\Models\StoreOrigin;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RajaOngkirService
{
    public function __construct(
        private readonly HttpFactory $http
    ) {
    }

    public function getProvinces(): array
    {
        $payload = $this->isKomerceApi()
            ? $this->get('destination/province')
            : $this->get('province');

        $rows = $this->extractResults($payload);

        return collect($rows)
            ->map(function (array $row): ?array {
                $id = $this->normalizeId($row['province_id'] ?? $row['id'] ?? null, 2);
                $name = $this->normalizeText($row['province'] ?? $row['name'] ?? null);
                if (! $id || ! $name) return null;

                return [
                    'id' => $id,
                    'name' => $name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getCities(string $provinceId): array
    {
        $requestProvinceId = $this->normalizeRequestId($provinceId);
        $payload = $this->isKomerceApi()
            ? $this->get("destination/city/{$requestProvinceId}")
            : $this->get('city', ['province' => $requestProvinceId]);

        $rows = $this->extractResults($payload);

        return collect($rows)
            ->map(function (array $row) use ($provinceId): ?array {
                $id = $this->normalizeId($row['city_id'] ?? $row['id'] ?? null, 4);
                $type = $this->normalizeText($row['type'] ?? null);
                $name = $this->normalizeText($row['city_name'] ?? $row['name'] ?? null);

                if (! $id || ! $name) return null;

                if (! $type && preg_match('/^(kabupaten|kota)\s+(.+)$/i', $name, $matches)) {
                    $type = Str::ucfirst(Str::lower($matches[1]));
                    $name = trim($matches[2]);
                }

                if (! $type) {
                    $type = 'Kota';
                }

                return [
                    'id' => $id,
                    'province_id' => $this->normalizeId($provinceId, 2),
                    'name' => $name,
                    'type' => $type,
                    'postal_code' => $this->normalizePostalCode($row['postal_code'] ?? null),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getDistricts(string $cityId): array
    {
        $requestCityId = $this->normalizeRequestId($cityId);
        $payload = $this->isKomerceApi()
            ? $this->get("destination/district/{$requestCityId}")
            : $this->get('subdistrict', ['city' => $requestCityId]);

        $rows = $this->extractResults($payload);

        return collect($rows)
            ->map(function (array $row) use ($cityId): ?array {
                $id = $this->normalizeId($row['district_id'] ?? $row['id'] ?? null, 7);
                $name = $this->normalizeText($row['district_name'] ?? $row['name'] ?? null);
                if (! $id || ! $name) return null;

                return [
                    'id' => $id,
                    'city_id' => $this->normalizeId($cityId, 4),
                    'name' => $name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getSubdistricts(string $districtId): array
    {
        $requestDistrictId = $this->normalizeRequestId($districtId);
        $payload = $this->isKomerceApi()
            ? $this->get("destination/sub-district/{$requestDistrictId}")
            : $this->get('subdistrict', ['district' => $requestDistrictId]);

        $rows = $this->extractResults($payload);

        return collect($rows)
            ->map(function (array $row): ?array {
                $id = $this->normalizeId($row['subdistrict_id'] ?? $row['id'] ?? null, 7);
                $name = $this->normalizeText($row['subdistrict_name'] ?? $row['name'] ?? null);
                if (! $id || ! $name) return null;

                return [
                    'id' => $id,
                    'name' => $name,
                    'zip_code' => $this->normalizePostalCode($row['zip_code'] ?? $row['postal_code'] ?? null),
                    'postal_code' => $this->normalizePostalCode($row['zip_code'] ?? $row['postal_code'] ?? null),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   source: 'store_origin'|'env',
     *   id: ?string,
     *   label: ?string,
     *   recipient_name: ?string,
     *   recipient_phone: ?string,
     *   province_id: ?string,
     *   province_name: ?string,
     *   city_id: string,
     *   city_name: ?string,
     *   district_id: ?string,
     *   district_name: ?string,
     *   subdistrict: ?string,
     *   address_detail: ?string,
     *   zip_code: ?string,
     *   location_note: ?string,
     *   full_address: ?string,
     *   is_active: bool,
     *   updated_at: ?string
     * }
     */
    public function getShippingOrigin(): array
    {
        $origin = StoreOrigin::query()
            ->with(['province:id,name', 'city:id,province_id,name,type,postal_code', 'district:id,city_id,name'])
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->first();

        if ($origin && trim((string) $origin->city_id) !== '') {
            return $this->mapStoreOrigin($origin);
        }

        $fallbackCityId = trim((string) config('services.rajaongkir.origin_city_id', ''));
        if ($fallbackCityId === '') {
            throw new RuntimeException(
                'Origin toko belum dikonfigurasi. Isi dulu di menu Vendor Pengiriman atau set RAJAONGKIR_ORIGIN_CITY_ID.'
            );
        }

        $normalizedCityId = $this->normalizeId($fallbackCityId, 4) ?? $fallbackCityId;
        $fallbackDistrictId = trim((string) config('services.rajaongkir.origin_district_id', ''));
        $normalizedDistrictId = $this->normalizeId($fallbackDistrictId, 7);
        $city = City::query()
            ->with('province:id,name')
            ->find($normalizedCityId);
        $district = null;
        if ($normalizedDistrictId !== null) {
            $district = District::query()->find($normalizedDistrictId);
            if (! $district) {
                throw new RuntimeException('RAJAONGKIR_ORIGIN_DISTRICT_ID tidak valid.');
            }

            if ((string) $district->city_id !== $normalizedCityId) {
                throw new RuntimeException('RAJAONGKIR_ORIGIN_DISTRICT_ID tidak sesuai dengan RAJAONGKIR_ORIGIN_CITY_ID.');
            }
        }

        $cityName = $city
            ? trim(implode(' ', array_filter([
                $this->normalizeText((string) $city->type),
                $this->normalizeText((string) $city->name),
            ])))
            : null;
        $provinceName = $city?->province?->name;
        $districtName = $district?->name;
        $zipCode = $this->normalizePostalCode($city?->postal_code);

        return [
            'source' => 'env',
            'id' => null,
            'label' => 'Default Origin (.env)',
            'recipient_name' => null,
            'recipient_phone' => null,
            'province_id' => $city?->province_id,
            'province_name' => $this->normalizeText($provinceName),
            'city_id' => $normalizedCityId,
            'city_name' => $this->normalizeText($cityName),
            'district_id' => $normalizedDistrictId,
            'district_name' => $this->normalizeText($districtName),
            'subdistrict' => null,
            'address_detail' => null,
            'zip_code' => $zipCode,
            'location_note' => null,
            'full_address' => $this->composeFullAddress([
                $districtName,
                $cityName,
                $provinceName,
                $zipCode,
            ]),
            'is_active' => true,
            'updated_at' => null,
        ];
    }

    /**
     * @return array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}>
     */
    public function getShippingCost(
        string $destinationCityId,
        int $weight,
        string $courier,
        ?string $destinationDistrictId = null
    ): array
    {
        $originProfile = $this->getShippingOrigin();
        $originCityId = trim((string) ($originProfile['city_id'] ?? ''));
        if ($originCityId === '') {
            throw new RuntimeException('Origin toko belum dikonfigurasi.');
        }

        $originDistrictId = trim((string) ($originProfile['district_id'] ?? ''));
        $normalizedOriginCityId = $this->normalizeId($originCityId, 4) ?? $originCityId;
        $normalizedDestinationCityId = $this->normalizeId($destinationCityId, 4) ?? $destinationCityId;
        $normalizedOriginDistrictId = $this->normalizeId($originDistrictId, 7);
        $normalizedDestinationDistrictId = $this->normalizeId($destinationDistrictId, 7);
        $normalizedCourier = Str::lower(trim($courier));
        $normalizedWeight = max(1, $weight);
        $strictMode = $this->isStrictMode();

        if ($normalizedCourier === '') {
            throw new RuntimeException('Kurir wajib dipilih untuk menghitung ongkir.');
        }

        if ($normalizedCourier === 'sicepat') {
            throw new RuntimeException('Kurir SiCepat belum tersedia pada integrasi RajaOngkir saat ini.');
        }

        if ($strictMode && $normalizedOriginDistrictId === null) {
            throw new RuntimeException('Asal toko wajib memiliki kecamatan aktif sebelum menghitung ongkir.');
        }

        if ($strictMode && $normalizedDestinationDistrictId === null) {
            throw new RuntimeException('Alamat tujuan wajib memiliki kecamatan yang valid sebelum menghitung ongkir.');
        }

        $cachedOptions = $strictMode
            ? []
            : $this->getCachedShippingOptions(
                originCityId: $normalizedOriginCityId,
                destinationCityId: $normalizedDestinationCityId,
                courier: $normalizedCourier,
                weight: $normalizedWeight
            );

        try {
            $payload = $this->postShippingCost(
                originCityId: $normalizedOriginCityId,
                destinationCityId: $normalizedDestinationCityId,
                weight: $normalizedWeight,
                courier: $normalizedCourier,
                originDistrictId: $normalizedOriginDistrictId,
                destinationDistrictId: $normalizedDestinationDistrictId,
                strictMode: $strictMode
            );
            $options = $this->extractShippingOptions($payload);
        } catch (Throwable $throwable) {
            if ($cachedOptions !== []) {
                return $cachedOptions;
            }

            if ($throwable instanceof RuntimeException) {
                throw $throwable;
            }

            throw new RuntimeException('Gagal menghitung ongkir dari RajaOngkir.', previous: $throwable);
        }

        if ($options === []) {
            if ($cachedOptions !== []) {
                return $cachedOptions;
            }

            throw new RuntimeException(
                $strictMode
                    ? 'Layanan pengiriman tidak tersedia untuk rute kecamatan ini.'
                    : 'Layanan pengiriman tidak tersedia untuk rute ini.'
            );
        }

        if (! $strictMode) {
            $this->cacheShippingOptions(
                originCityId: $normalizedOriginCityId,
                destinationCityId: $normalizedDestinationCityId,
                courier: $normalizedCourier,
                weight: $normalizedWeight,
                options: $options
            );
        }

        return $options;
    }

    private function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, $query);
    }

    private function post(string $endpoint, array $payload = []): array
    {
        return $this->request('POST', $endpoint, payload: $payload);
    }

    private function request(string $method, string $endpoint, array $query = [], array $payload = []): array
    {
        $apiKey = (string) config('services.rajaongkir.key', '');
        $baseUrl = rtrim((string) config('services.rajaongkir.base_url', 'https://rajaongkir.komerce.id/api/v1'), '/');
        $timeout = (int) config('services.rajaongkir.timeout', 20);

        if ($apiKey === '') {
            throw new RuntimeException('RAJAONGKIR_API_KEY belum dikonfigurasi.');
        }

        try {
            $request = $this->http
                ->withHeaders([
                    'key' => $apiKey,
                    'x-api-key' => $apiKey,
                    'Authorization' => "Bearer {$apiKey}",
                ])
                ->timeout($timeout)
                ->acceptJson();

            $url = "{$baseUrl}/{$endpoint}";
            if (Str::upper($method) === 'POST') {
                if ($this->isKomerceApi()) {
                    $request = $request->asForm();
                }

                $response = $request->post($url, $payload);
            } else {
                $response = $request->get($url, $query);
            }
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Tidak bisa terhubung ke RajaOngkir. Periksa RAJAONGKIR_BASE_URL, API key, dan koneksi server.',
                previous: $throwable
            );
        }

        if (! $response->successful()) {
            $errorMessage = $this->extractErrorMessage($response->json());
            $suffix = $errorMessage ? " {$errorMessage}" : '';

            throw new RuntimeException("Gagal mengakses RajaOngkir.{$suffix}");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Respons RajaOngkir tidak valid.');
        }

        if ($this->hasErrorPayload($payload)) {
            $errorMessage = $this->extractErrorMessage($payload);
            $suffix = $errorMessage ? " {$errorMessage}" : '';

            throw new RuntimeException("Gagal mengambil data wilayah dari RajaOngkir.{$suffix}");
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function postShippingCost(
        string $originCityId,
        string $destinationCityId,
        int $weight,
        string $courier,
        ?string $originDistrictId = null,
        ?string $destinationDistrictId = null,
        bool $strictMode = false
    ): array
    {
        $originCity = $this->normalizeRequestId($originCityId);
        $destinationCity = $this->normalizeRequestId($destinationCityId);
        $attempts = [];

        if ($originDistrictId !== null && $destinationDistrictId !== null) {
            $attempts[] = ['endpoint' => 'calculate/district/domestic-cost', 'payload' => [
                'origin' => $this->normalizeRequestId($originDistrictId),
                'destination' => $this->normalizeRequestId($destinationDistrictId),
                'weight' => $weight,
                'courier' => $courier,
                'price' => 'lowest',
            ]];
        }

        if (! $strictMode) {
            $attempts[] = ['endpoint' => 'calculate/domestic-cost', 'payload' => [
                'origin' => $originCity,
                'destination' => $destinationCity,
                'weight' => $weight,
                'courier' => $courier,
            ]];
            $attempts[] = ['endpoint' => 'cost', 'payload' => [
                'origin' => $originCity,
                'destination' => $destinationCity,
                'weight' => $weight,
                'courier' => $courier,
            ]];
        }

        $lastError = null;

        foreach ($attempts as $attempt) {
            try {
                return $this->post($attempt['endpoint'], $attempt['payload']);
            } catch (Throwable $throwable) {
                $lastError = $throwable;
            }
        }

        $reason = $lastError?->getMessage();
        if (is_string($reason) && str_contains(Str::lower($reason), 'not found')) {
            throw new RuntimeException(
                $strictMode
                    ? "Kurir {$courier} tidak tersedia untuk perhitungan ongkir level kecamatan saat ini."
                    : "Kurir {$courier} tidak tersedia untuk integrasi RajaOngkir saat ini.",
                previous: $lastError
            );
        }

        $suffix = is_string($reason) && trim($reason) !== '' ? ' ' . trim($reason) : '';

        throw new RuntimeException(
            $strictMode
                ? "Gagal menghitung ongkir level kecamatan dari RajaOngkir.{$suffix}"
                : "Gagal menghitung ongkir dari RajaOngkir.{$suffix}",
            previous: $lastError
        );
    }

    /**
     * @return array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}>
     */
    private function getCachedShippingOptions(
        string $originCityId,
        string $destinationCityId,
        string $courier,
        int $weight
    ): array {
        return ShippingRateCache::query()
            ->where('origin_city_id', $originCityId)
            ->where('destination_city_id', $destinationCityId)
            ->where('courier', $courier)
            ->where('weight', $weight)
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('cost')
            ->get(['service', 'cost', 'etd', 'cached_at'])
            ->map(fn (ShippingRateCache $row): array => [
                'service' => (string) ($row->service ?? ''),
                'description' => null,
                'cost' => max(0, (int) $row->cost),
                'etd' => $this->normalizeText($row->etd),
                'note' => 'cached',
            ])
            ->filter(fn (array $row): bool => $row['service'] !== '' && $row['cost'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}> $options
     */
    private function cacheShippingOptions(
        string $originCityId,
        string $destinationCityId,
        string $courier,
        int $weight,
        array $options
    ): void {
        if ($options === []) {
            return;
        }

        if (! $this->isCityKnown($originCityId) || ! $this->isCityKnown($destinationCityId)) {
            return;
        }

        $expiresAt = now()->addHours(max(1, (int) config('services.rajaongkir.cache_ttl_hours', 6)));
        $rows = collect($options)
            ->filter(fn (array $option): bool => trim((string) ($option['service'] ?? '')) !== '')
            ->map(fn (array $option): array => [
                'origin_city_id' => $originCityId,
                'destination_city_id' => $destinationCityId,
                'courier' => $courier,
                'service' => trim((string) ($option['service'] ?? '')),
                'weight' => $weight,
                'cost' => max(0, (int) ($option['cost'] ?? 0)),
                'etd' => $this->normalizeText($option['etd'] ?? null),
                'cached_at' => now(),
                'expires_at' => $expiresAt,
            ])
            ->filter(fn (array $row): bool => $row['cost'] > 0)
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($originCityId, $destinationCityId, $courier, $weight, $rows): void {
            ShippingRateCache::query()
                ->where('origin_city_id', $originCityId)
                ->where('destination_city_id', $destinationCityId)
                ->where('courier', $courier)
                ->where('weight', $weight)
                ->delete();

            ShippingRateCache::query()->insert($rows);
        });
    }

    private function isCityKnown(string $cityId): bool
    {
        return City::query()->whereKey($cityId)->exists();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}>
     */
    private function extractShippingOptions(array $payload): array
    {
        $results = Arr::get($payload, 'data');
        if (! is_array($results)) {
            $results = Arr::get($payload, 'rajaongkir.results');
        }
        if (! is_array($results)) {
            $results = Arr::get($payload, 'results');
        }
        if (! is_array($results)) {
            return [];
        }

        $rows = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            if (isset($result['costs']) && is_array($result['costs'])) {
                foreach ($result['costs'] as $costRow) {
                    $parsed = $this->normalizeCostRow($costRow);
                    if ($parsed !== null) {
                        $rows[] = $parsed;
                    }
                }

                continue;
            }

            $parsed = $this->normalizeCostRow($result);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }

        return collect($rows)
            ->sortBy('cost')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{service: string, description: ?string, cost: int, etd: ?string, note: ?string}|null
     */
    private function normalizeCostRow(array $row): ?array
    {
        $service = trim((string) ($row['service'] ?? $row['code'] ?? ''));
        if ($service === '') {
            return null;
        }

        $costSource = $row['cost'] ?? null;
        if (is_array($costSource) && isset($costSource[0]) && is_array($costSource[0])) {
            $costSource = $costSource[0];
        }

        $value = (int) ($costSource['value'] ?? $row['price'] ?? $row['cost'] ?? 0);
        if ($value <= 0) {
            return null;
        }

        $description = $this->normalizeText($row['description'] ?? $row['service_name'] ?? null);
        $etd = $this->normalizeText($costSource['etd'] ?? $row['etd'] ?? null);
        $note = $this->normalizeText($costSource['note'] ?? $row['note'] ?? null);

        return [
            'service' => $service,
            'description' => $description,
            'cost' => $value,
            'etd' => $etd,
            'note' => $note,
        ];
    }

    private function extractResults(array $payload): array
    {
        $directData = Arr::get($payload, 'data');
        if (is_array($directData)) {
            return $directData;
        }

        $wrapped = Arr::get($payload, 'rajaongkir.results');
        if (is_array($wrapped)) {
            return $wrapped;
        }

        $direct = $payload['results'] ?? null;
        if (is_array($direct)) {
            return $direct;
        }

        return [];
    }

    private function extractErrorMessage(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $message = Arr::get($payload, 'meta.message')
            ?? Arr::get($payload, 'message')
            ?? Arr::get($payload, 'error')
            ?? Arr::get($payload, 'rajaongkir.status.description');

        if (! is_string($message)) {
            return null;
        }

        $trimmed = trim($message);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function hasErrorPayload(array $payload): bool
    {
        $metaCode = Arr::get($payload, 'meta.code');
        if (is_numeric($metaCode) && (int) $metaCode >= 400) {
            return true;
        }

        $metaStatus = Arr::get($payload, 'meta.status');
        if (is_string($metaStatus)) {
            $normalizedStatus = Str::lower(trim($metaStatus));
            if (! in_array($normalizedStatus, ['success', 'ok'], true)) {
                return true;
            }
        }

        if (is_bool($metaStatus) && $metaStatus === false) {
            return true;
        }

        $legacyCode = Arr::get($payload, 'rajaongkir.status.code');
        if (is_numeric($legacyCode) && (int) $legacyCode >= 400) {
            return true;
        }

        return false;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeId(mixed $value, int $length): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) (int) $value;
        }

        if (! is_string($value)) return null;
        $clean = preg_replace('/\D+/', '', trim($value));
        if (! $clean) return null;

        return str_pad($clean, $length, '0', STR_PAD_LEFT);
    }

    private function normalizePostalCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) return null;
        $clean = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($clean === '') return null;
        return str_pad(substr($clean, 0, 5), 5, '0', STR_PAD_LEFT);
    }

    private function normalizeRequestId(string $value): string
    {
        $normalized = $this->normalizeId($value, 1);
        if (! $normalized) {
            return $value;
        }

        $trimmed = ltrim($normalized, '0');
        return $trimmed !== '' ? $trimmed : '0';
    }

    private function isKomerceApi(): bool
    {
        $baseUrl = (string) config('services.rajaongkir.base_url', '');
        return str_contains(Str::lower($baseUrl), 'rajaongkir.komerce.id');
    }

    private function isStrictMode(): bool
    {
        return (bool) config('services.rajaongkir.strict_mode', false);
    }

    /**
     * @return array{
     *   source: 'store_origin',
     *   id: ?string,
     *   label: ?string,
     *   recipient_name: ?string,
     *   recipient_phone: ?string,
     *   province_id: ?string,
     *   province_name: ?string,
     *   city_id: string,
     *   city_name: ?string,
     *   district_id: ?string,
     *   district_name: ?string,
     *   subdistrict: ?string,
     *   address_detail: ?string,
     *   zip_code: ?string,
     *   location_note: ?string,
     *   full_address: ?string,
     *   is_active: bool,
     *   updated_at: ?string
     * }
     */
    private function mapStoreOrigin(StoreOrigin $origin): array
    {
        $cityName = $origin->city
            ? trim(implode(' ', array_filter([
                $this->normalizeText((string) $origin->city->type),
                $this->normalizeText((string) $origin->city->name),
            ])))
            : null;
        $provinceName = $origin->province?->name;
        $districtName = $origin->district?->name;
        $zipCode = $this->normalizePostalCode($origin->zip_code) ?? $this->normalizePostalCode($origin->city?->postal_code);

        return [
            'source' => 'store_origin',
            'id' => $origin->id,
            'label' => $this->normalizeText($origin->label),
            'recipient_name' => $this->normalizeText($origin->recipient_name),
            'recipient_phone' => $this->normalizeText($origin->recipient_phone),
            'province_id' => $this->normalizeText($origin->province_id),
            'province_name' => $this->normalizeText($provinceName),
            'city_id' => (string) $origin->city_id,
            'city_name' => $this->normalizeText($cityName),
            'district_id' => $this->normalizeText($origin->district_id),
            'district_name' => $this->normalizeText($districtName),
            'subdistrict' => $this->normalizeText($origin->subdistrict),
            'address_detail' => $this->normalizeText($origin->address_detail),
            'zip_code' => $zipCode,
            'location_note' => $this->normalizeText($origin->location_note),
            'full_address' => $this->composeFullAddress([
                $origin->address_detail,
                $origin->subdistrict,
                $districtName,
                $cityName,
                $provinceName,
                $zipCode,
            ]),
            'is_active' => (bool) $origin->is_active,
            'updated_at' => optional($origin->updated_at)?->toISOString(),
        ];
    }

    /**
     * @param array<int, mixed> $parts
     */
    private function composeFullAddress(array $parts): ?string
    {
        $filtered = collect($parts)
            ->map(function (mixed $part): ?string {
                if (! is_string($part) && ! is_numeric($part)) {
                    return null;
                }

                return $this->normalizeText((string) $part);
            })
            ->filter()
            ->values()
            ->all();

        if ($filtered === []) {
            return null;
        }

        return implode(', ', $filtered);
    }
}
