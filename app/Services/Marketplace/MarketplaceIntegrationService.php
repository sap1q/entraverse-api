<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceMapping;
use App\Models\Product;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MarketplaceIntegrationService
{
    private const FRONTEND_DEFAULT_PATH = '/admin/marketplace-produk';
    private const STATE_TTL_SECONDS = 600;

    /** @var array<int, string> */
    private const CHANNELS = ['tiktok', 'shopee'];

    /**
     * @return array{
     *     connections: array<string, array<string, mixed>>,
     *     supported: bool,
     *     message: string|null,
     * }
     */
    public function listConnections(): array
    {
        $records = MarketplaceConnection::query()
            ->whereIn('channel', self::CHANNELS)
            ->get()
            ->keyBy('channel');

        $connections = [];
        $supported = false;

        foreach (self::CHANNELS as $channel) {
            $connection = $records->get($channel);
            $connections[$channel] = $this->serializeConnection($channel, $connection);
            $supported = $supported || $this->channelConfigured($channel) || $connection !== null;
        }

        return [
            'connections' => $connections,
            'supported' => $supported,
            'message' => $supported ? null : 'Credential TikTok Shop dan Shopee belum dikonfigurasi di backend.',
        ];
    }

    public function beginAuthorization(string $channel, ?string $frontendRedirect = null): string
    {
        $this->assertValidChannel($channel);
        $this->assertChannelConfigured($channel);

        $state = Str::random(40);
        $payload = [
            'frontend_redirect' => $this->normalizeFrontendRedirect($frontendRedirect),
            'created_at' => now()->toISOString(),
        ];

        Cache::put($this->stateCacheKey($channel, $state), $payload, self::STATE_TTL_SECONDS);
        Cache::put($this->pendingRedirectCacheKey($channel), $payload, self::STATE_TTL_SECONDS);

        return match ($channel) {
            'tiktok' => $this->buildTikTokAuthorizationUrl($state),
            'shopee' => $this->buildShopeeAuthorizationUrl(),
            default => throw new MarketplaceIntegrationException('Channel marketplace tidak dikenali.'),
        };
    }

    public function finalizeAuthorization(string $channel, array $query): string
    {
        $this->assertValidChannel($channel);

        $state = trim((string) ($query['state'] ?? ''));
        $statePayload = $state !== '' ? Cache::pull($this->stateCacheKey($channel, $state)) : null;
        $pendingPayload = Cache::pull($this->pendingRedirectCacheKey($channel));
        $frontendRedirect = $this->extractFrontendRedirect($statePayload, $pendingPayload);

        $errorMessage = trim((string) ($query['message'] ?? $query['error_message'] ?? $query['error'] ?? ''));
        if ($errorMessage !== '') {
            $this->markConnectionAsErrored($channel, $errorMessage, ['callback' => $query]);
            return $this->buildFrontendCallbackUrl($frontendRedirect, $channel, 'error', $errorMessage);
        }

        try {
            $tokens = match ($channel) {
                'tiktok' => $this->exchangeTikTokAuthorizationCode($query),
                'shopee' => $this->exchangeShopeeAuthorizationCode($query),
                default => throw new MarketplaceIntegrationException('Channel marketplace tidak dikenali.'),
            };

            $connection = MarketplaceConnection::query()->firstOrNew([
                'channel' => $channel,
            ]);

            $connection->fill([
                'status' => 'connected',
                'shop_id' => $tokens['shop_id'] ?? null,
                'shop_name' => $tokens['shop_name'] ?? null,
                'seller_id' => $tokens['seller_id'] ?? null,
                'access_token' => $tokens['access_token'] ?? null,
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => $tokens['token_expires_at'] ?? null,
                'connected_at' => now(),
                'last_error' => null,
                'metadata' => $tokens['metadata'] ?? null,
            ])->save();

            MarketplaceMapping::query()
                ->where('channel', $channel)
                ->update(['marketplace_connection_id' => $connection->id]);

            return $this->buildFrontendCallbackUrl($frontendRedirect, $channel, 'connected');
        } catch (MarketplaceIntegrationException $exception) {
            $this->markConnectionAsErrored($channel, $exception->getMessage(), ['callback' => $query]);

            return $this->buildFrontendCallbackUrl($frontendRedirect, $channel, 'error', $exception->getMessage());
        }
    }

    public function disconnect(string $channel): MarketplaceConnection
    {
        $this->assertValidChannel($channel);

        $connection = MarketplaceConnection::query()->firstOrNew([
            'channel' => $channel,
        ]);

        $connection->fill([
            'status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'last_error' => null,
            'metadata' => array_merge((array) ($connection->metadata ?? []), [
                'disconnected_at' => now()->toISOString(),
            ]),
        ])->save();

        MarketplaceMapping::query()
            ->where('channel', $channel)
            ->update(['marketplace_connection_id' => null]);

        return $connection->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $channel): array
    {
        $this->assertValidChannel($channel);
        $connection = $this->resolveConnectedConnection($channel);

        if (! $this->channelConfigured($channel)) {
            throw new MarketplaceIntegrationException("Credential {$this->displayName($channel)} belum lengkap di backend.");
        }

        $mappings = MarketplaceMapping::query()
            ->where('channel', $channel)
            ->whereNotNull('marketplace_connection_id')
            ->get();

        if ($mappings->isEmpty()) {
            throw new MarketplaceIntegrationException("Belum ada varian yang dimapping ke {$this->displayName($channel)}.");
        }

        $needsRemoteIds = $mappings->filter(fn (MarketplaceMapping $mapping) => blank($mapping->marketplace_product_id));
        if ($needsRemoteIds->isNotEmpty()) {
            throw new MarketplaceIntegrationException(
                "Sync {$this->displayName($channel)} butuh marketplace_product_id untuk setiap mapping. Sambungkan mapping detail item/model dulu."
            );
        }

        throw new MarketplaceIntegrationException(
            "Outbound sync {$this->displayName($channel)} belum bisa dijalankan otomatis sebelum payload produk/item platform disesuaikan."
        );
    }

    public function upsertMapping(
        string $channel,
        Product $product,
        string $variantKey,
        ?string $sellerSku = null,
        ?string $marketplaceProductId = null,
        ?string $marketplaceSkuId = null
    ): MarketplaceMapping {
        $this->assertValidChannel($channel);

        $connection = MarketplaceConnection::query()
            ->where('channel', $channel)
            ->where('status', 'connected')
            ->first();

        $mapping = MarketplaceMapping::query()->updateOrCreate(
            [
                'channel' => $channel,
                'product_id' => $product->id,
                'variant_key' => $variantKey,
            ],
            [
                'marketplace_connection_id' => $connection?->id,
                'seller_sku' => $this->nullIfBlank($sellerSku),
                'marketplace_product_id' => $this->nullIfBlank($marketplaceProductId),
                'marketplace_sku_id' => $this->nullIfBlank($marketplaceSkuId),
                'status' => 'mapped',
                'last_error' => null,
                'payload' => [
                    'variant_key' => $variantKey,
                    'updated_at' => now()->toISOString(),
                ],
            ]
        );

        return $mapping->fresh();
    }

    public function deleteMapping(string $channel, Product $product, string $variantKey): void
    {
        $this->assertValidChannel($channel);

        MarketplaceMapping::query()
            ->where('channel', $channel)
            ->where('product_id', $product->id)
            ->where('variant_key', $variantKey)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConnection(string $channel, ?MarketplaceConnection $connection): array
    {
        return [
            'channel' => $channel,
            'status' => $connection?->status ?? 'disconnected',
            'connected' => ($connection?->status ?? 'disconnected') === 'connected',
            'shop_id' => $connection?->shop_id,
            'shop_name' => $connection?->shop_name,
            'seller_id' => $connection?->seller_id,
            'authorization_url' => null,
            'connected_at' => optional($connection?->connected_at)?->toISOString(),
            'expires_at' => optional($connection?->token_expires_at)?->toISOString(),
            'last_inbound_sync_at' => optional($connection?->last_inbound_sync_at)?->toISOString(),
            'last_outbound_sync_at' => optional($connection?->last_outbound_sync_at)?->toISOString(),
            'last_error' => $connection?->last_error,
        ];
    }

    private function buildTikTokAuthorizationUrl(string $state): string
    {
        $config = (array) config('services.tiktok_shop');
        $baseUrl = rtrim((string) ($config['authorize_url'] ?? 'https://services.tiktokshop.com/open/authorize'), '/');

        return $baseUrl . '?' . http_build_query([
            'app_key' => (string) ($config['app_key'] ?? ''),
            'redirect_uri' => $this->callbackUrl('tiktok'),
            'state' => $state,
        ]);
    }

    private function buildShopeeAuthorizationUrl(): string
    {
        $config = (array) config('services.shopee');
        $baseUrl = rtrim((string) ($config['authorize_url'] ?? 'https://partner.shopeemobile.com/api/v2/shop/auth_partner'), '/');
        $timestamp = time();
        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $partnerId = (string) ($config['partner_id'] ?? '');
        $partnerKey = (string) ($config['partner_key'] ?? '');
        $signatureBase = $partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $signatureBase, $partnerKey);

        return $baseUrl . '?' . http_build_query([
            'partner_id' => $partnerId,
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $this->callbackUrl('shopee'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function exchangeTikTokAuthorizationCode(array $query): array
    {
        $this->assertChannelConfigured('tiktok');

        $code = $this->nullIfBlank((string) ($query['code'] ?? $query['auth_code'] ?? ''));
        if ($code === null) {
            throw new MarketplaceIntegrationException('Callback TikTok Shop tidak membawa authorization code.');
        }

        $config = (array) config('services.tiktok_shop');
        $tokenUrl = (string) ($config['token_url'] ?? 'https://auth.tiktok-shops.com/api/v2/token/get');

        try {
            $response = Http::asJson()
                ->timeout((int) config('services.tiktok_shop.timeout', 30))
                ->post($tokenUrl, [
                    'app_key' => (string) ($config['app_key'] ?? ''),
                    'app_secret' => (string) ($config['app_secret'] ?? ''),
                    'grant_type' => 'authorized_code',
                    'auth_code' => $code,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new MarketplaceIntegrationException(
                'Token exchange TikTok Shop gagal: ' . $exception->getMessage(),
                502
            );
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : (is_array($response) ? $response : []);
        $accessToken = $this->nullIfBlank((string) ($data['access_token'] ?? ''));

        if ($accessToken === null) {
            throw new MarketplaceIntegrationException('TikTok Shop tidak mengembalikan access_token yang valid.', 502);
        }

        $expiresIn = (int) ($data['access_token_expire_in'] ?? $data['expires_in'] ?? 0);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $this->nullIfBlank((string) ($data['refresh_token'] ?? '')),
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'shop_id' => $this->nullIfBlank((string) ($data['shop_id'] ?? $query['shop_id'] ?? '')),
            'shop_name' => $this->nullIfBlank((string) ($data['shop_name'] ?? '')),
            'seller_id' => $this->nullIfBlank((string) ($data['seller_id'] ?? $data['open_id'] ?? '')),
            'metadata' => [
                'callback' => $query,
                'token_response' => $data,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function exchangeShopeeAuthorizationCode(array $query): array
    {
        $this->assertChannelConfigured('shopee');

        $code = $this->nullIfBlank((string) ($query['code'] ?? ''));
        $shopId = $this->nullIfBlank((string) ($query['shop_id'] ?? ''));

        if ($code === null || $shopId === null) {
            throw new MarketplaceIntegrationException('Callback Shopee tidak membawa code atau shop_id.');
        }

        $config = (array) config('services.shopee');
        $tokenUrl = (string) ($config['token_url'] ?? 'https://partner.shopeemobile.com/api/v2/auth/token/get');
        $partnerId = (string) ($config['partner_id'] ?? '');
        $partnerKey = (string) ($config['partner_key'] ?? '');
        $timestamp = time();
        $path = (string) parse_url($tokenUrl, PHP_URL_PATH);
        $signatureBase = $partnerId . $path . $timestamp;
        $sign = hash_hmac('sha256', $signatureBase, $partnerKey);

        try {
            $response = Http::asJson()
                ->timeout((int) config('services.shopee.timeout', 30))
                ->post($tokenUrl . '?' . http_build_query([
                    'partner_id' => $partnerId,
                    'timestamp' => $timestamp,
                    'sign' => $sign,
                ]), [
                    'partner_id' => (int) $partnerId,
                    'shop_id' => (int) $shopId,
                    'code' => $code,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new MarketplaceIntegrationException(
                'Token exchange Shopee gagal: ' . $exception->getMessage(),
                502
            );
        }

        $data = is_array($response) ? $response : [];
        $accessToken = $this->nullIfBlank((string) ($data['access_token'] ?? ''));

        if ($accessToken === null) {
            throw new MarketplaceIntegrationException('Shopee tidak mengembalikan access_token yang valid.', 502);
        }

        $expiresIn = (int) ($data['expire_in'] ?? 0);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $this->nullIfBlank((string) ($data['refresh_token'] ?? '')),
            'token_expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            'shop_id' => $shopId,
            'shop_name' => $this->nullIfBlank((string) ($data['shop_name'] ?? '')),
            'seller_id' => $this->nullIfBlank((string) ($data['merchant_id'] ?? $data['merchant_id_list'][0] ?? '')),
            'metadata' => [
                'callback' => $query,
                'token_response' => $data,
            ],
        ];
    }

    private function resolveConnectedConnection(string $channel): MarketplaceConnection
    {
        $connection = MarketplaceConnection::query()
            ->where('channel', $channel)
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            throw new MarketplaceIntegrationException("{$this->displayName($channel)} belum tersambung.");
        }

        return $connection;
    }

    private function assertValidChannel(string $channel): void
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new MarketplaceIntegrationException('Channel marketplace tidak dikenali.', 404);
        }
    }

    private function assertChannelConfigured(string $channel): void
    {
        if (! $this->channelConfigured($channel)) {
            throw new MarketplaceIntegrationException(
                "Credential {$this->displayName($channel)} belum lengkap di .env backend."
            );
        }
    }

    private function channelConfigured(string $channel): bool
    {
        return match ($channel) {
            'tiktok' => blank(config('services.tiktok_shop.app_key')) === false
                && blank(config('services.tiktok_shop.app_secret')) === false,
            'shopee' => blank(config('services.shopee.partner_id')) === false
                && blank(config('services.shopee.partner_key')) === false,
            default => false,
        };
    }

    private function callbackUrl(string $channel): string
    {
        return match ($channel) {
            'tiktok' => (string) (config('services.tiktok_shop.redirect_uri') ?: url("/api/v1/integrations/marketplaces/{$channel}/callback")),
            'shopee' => (string) (config('services.shopee.redirect_uri') ?: url("/api/v1/integrations/marketplaces/{$channel}/callback")),
            default => url("/api/v1/integrations/marketplaces/{$channel}/callback"),
        };
    }

    private function stateCacheKey(string $channel, string $state): string
    {
        return "marketplace_oauth_state:{$channel}:{$state}";
    }

    private function pendingRedirectCacheKey(string $channel): string
    {
        return "marketplace_oauth_pending_redirect:{$channel}";
    }

    /**
     * @param  array<string, mixed>|null  $statePayload
     * @param  array<string, mixed>|null  $pendingPayload
     */
    private function extractFrontendRedirect(?array $statePayload, ?array $pendingPayload): string
    {
        return $this->normalizeFrontendRedirect(
            (string) Arr::get($statePayload, 'frontend_redirect', Arr::get($pendingPayload, 'frontend_redirect', self::FRONTEND_DEFAULT_PATH))
        );
    }

    private function normalizeFrontendRedirect(?string $path): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/');
        $rawPath = trim((string) ($path ?: self::FRONTEND_DEFAULT_PATH));

        if (Str::startsWith($rawPath, ['http://', 'https://'])) {
            return $rawPath;
        }

        $normalizedPath = '/' . ltrim($rawPath, '/');

        return $frontendUrl . $normalizedPath;
    }

    private function buildFrontendCallbackUrl(string $baseUrl, string $channel, string $status, ?string $message = null): string
    {
        $query = array_filter([
            'marketplace' => $channel,
            'connection_status' => $status,
            'message' => $message,
        ], static fn ($value) => $value !== null && $value !== '');

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function markConnectionAsErrored(string $channel, string $message, array $metadata = []): void
    {
        $connection = MarketplaceConnection::query()->firstOrNew([
            'channel' => $channel,
        ]);

        $connection->fill([
            'status' => 'error',
            'last_error' => $message,
            'metadata' => array_merge((array) ($connection->metadata ?? []), $metadata),
        ])->save();
    }

    private function displayName(string $channel): string
    {
        return match ($channel) {
            'tiktok' => 'TikTok Shop',
            'shopee' => 'Shopee',
            default => ucfirst($channel),
        };
    }

    private function nullIfBlank(?string $value): ?string
    {
        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }
}
