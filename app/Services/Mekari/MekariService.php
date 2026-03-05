<?php

declare(strict_types=1);

namespace App\Services\Mekari;

use App\Services\Mekari\Exceptions\MekariApiException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class MekariService
{
    protected readonly string $clientId;

    protected readonly string $clientSecret;

    protected readonly string $baseUrl;

    protected readonly ClientInterface $httpClient;

    protected readonly bool $debugSigning;

    public function __construct(
        ?ClientInterface $httpClient = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $baseUrl = null
    ) {
        $this->clientId = $clientId ?? (string) $this->configValue('services.mekari.client_id', '');
        $this->clientSecret = $clientSecret ?? (string) $this->configValue('services.mekari.client_secret', '');
        $this->baseUrl = rtrim((string) ($baseUrl ?? $this->configValue('services.mekari.base_url', 'https://api.mekari.com')), '/');
        $this->debugSigning = $this->toBoolean($this->configValue('services.mekari.debug_signing', false));

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new InvalidArgumentException('Mekari credentials are missing. Configure services.mekari.client_id and services.mekari.client_secret.');
        }

        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => (float) $this->configValue('services.mekari.timeout', 30),
            'connect_timeout' => (float) $this->configValue('services.mekari.connect_timeout', 10),
            'http_errors' => false,
        ]);
    }

    /**
     * Generate HMAC signature sesuai dokumentasi Mekari.
     *
     * Format payload:
     * date: {RFC7231 date}\n{METHOD} {path} HTTP/1.1
     */
    public function generateSignature(string $method, string $path, string $date): string
    {
        $method = strtoupper($method);
        $normalizedPath = $this->normalizePathForSigning($path);
        $requestLine = "{$method} {$normalizedPath} HTTP/1.1";
        $payload = "date: {$date}\n{$requestLine}";

        if ($this->debugSigning) {
            $this->log('info', 'Mekari signature payload generated.', [
                'method' => $method,
                'path' => $normalizedPath,
                'date' => $date,
                'request_line' => $requestLine,
                'payload' => $payload,
            ]);
        }

        $digest = hash_hmac('sha256', $payload, $this->clientSecret, true);

        return base64_encode($digest);
    }

    public function buildAuthHeader(string $signature): string
    {
        return sprintf(
            'hmac username="%s", algorithm="hmac-sha256", headers="date request-line", signature="%s"',
            $this->clientId,
            $signature
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function generateDigest(array $body): string
    {
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $jsonBody, true);

        return 'SHA-256='.base64_encode($hash);
    }

    /**
     * @param array{
     *     body?: array<string, mixed>|null,
     *     headers?: array<string, string>,
     *     query?: array<string, scalar|array<array-key, scalar|null>|null>
     * } $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        $method = strtoupper($method);
        $endpointPath = $this->normalizePath($endpoint);
        $query = $this->resolveQuery($endpoint, $options['query'] ?? []);
        $pathWithQuery = $this->appendQueryToPath($endpointPath, $query);
        $body = $options['body'] ?? null;
        $date = CarbonImmutable::now('GMT')->toRfc7231String();

        $authHeaders = $this->buildSignedHeaders(
            method: $method,
            pathWithQuery: $pathWithQuery,
            date: $date,
            body: is_array($body) ? $body : null
        );
        $headers = array_merge($authHeaders, $options['headers'] ?? []);

        $this->log('info', 'Mekari request prepared.', [
            'method' => $method,
            'endpoint' => $endpoint,
            'path' => $pathWithQuery,
            'date' => $date,
            'client_id' => $this->clientId,
            'has_secret' => $this->clientSecret !== '',
            'has_body' => is_array($body),
            'has_digest' => isset($headers['Digest']),
        ]);

        if ($this->debugSigning) {
            $this->log('info', 'Mekari auth header ready.', [
                'authorization' => $headers['Authorization'] ?? null,
                'signature' => $this->extractSignature((string) ($headers['Authorization'] ?? '')),
            ]);
        }

        $requestOptions = ['headers' => $headers];
        if ($query !== []) {
            $requestOptions['query'] = $query;
        }
        if ($body !== null) {
            $requestOptions['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $endpointPath, $requestOptions);
            $statusCode = $response->getStatusCode();
            $responseBody = $this->decodeResponseBody((string) $response->getBody());

            $this->log('info', 'Mekari API request completed.', [
                'method' => $method,
                'endpoint' => $endpointPath,
                'query' => $query,
                'status' => $statusCode,
                'response_message' => Arr::get($responseBody, 'message'),
            ]);

            if ($statusCode >= 400) {
                throw new MekariApiException(
                    message: (string) (Arr::get($responseBody, 'message')
                        ?? Arr::get($responseBody, 'error.message')
                        ?? 'Mekari API returned an error response.'),
                    statusCode: $statusCode,
                    responseData: $responseBody
                );
            }

            return $responseBody;
        } catch (ConnectException|TransferException $exception) {
            $this->log('error', 'Mekari API transport error.', [
                'method' => $method,
                'endpoint' => $endpointPath,
                'query' => $query,
                'error' => $exception->getMessage(),
            ]);

            throw new MekariApiException(
                message: 'Failed to connect to Mekari API.',
                statusCode: 0,
                responseData: null,
                previous: $exception
            );
        }
    }

    protected function normalizePath(string $endpoint): string
    {
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        return str_starts_with($path, '/') ? $path : "/{$path}";
    }

    protected function normalizePathForSigning(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);
        $queryString = (string) parse_url($path, PHP_URL_QUERY);

        return $queryString !== '' ? "{$normalizedPath}?{$queryString}" : $normalizedPath;
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $query
     */
    protected function appendQueryToPath(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $queryString !== '' ? "{$path}?{$queryString}" : $path;
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $queryFromOptions
     * @return array<string, scalar|array<array-key, scalar|null>|null>
     */
    protected function resolveQuery(string $endpoint, array $queryFromOptions): array
    {
        $queryFromEndpoint = [];
        $queryString = (string) parse_url($endpoint, PHP_URL_QUERY);
        if ($queryString !== '') {
            parse_str($queryString, $queryFromEndpoint);
        }

        if ($queryFromEndpoint === []) {
            return $queryFromOptions;
        }

        if ($queryFromOptions === []) {
            return $queryFromEndpoint;
        }

        return array_replace($queryFromEndpoint, $queryFromOptions);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, string>
     */
    protected function buildSignedHeaders(string $method, string $pathWithQuery, string $date, ?array $body = null): array
    {
        $signature = $this->generateSignature($method, $pathWithQuery, $date);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Date' => $date,
            'Authorization' => $this->buildAuthHeader($signature),
        ];

        if ($body !== null) {
            $headers['Digest'] = $this->generateDigest($body);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeResponseBody(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['raw' => $payload];
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return ['value' => $decoded];
    }

    protected function extractSignature(string $authorizationHeader): ?string
    {
        if (! str_contains($authorizationHeader, 'signature="')) {
            return null;
        }

        preg_match('/signature="([^"]+)"/', $authorizationHeader, $matches);

        return $matches[1] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (Throwable) {
            // Ignore logging errors outside Laravel runtime.
        }
    }

    protected function configValue(string $key, mixed $default = null): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    protected function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? false;
        }

        return (bool) $value;
    }
}
