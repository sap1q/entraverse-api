<?php

declare(strict_types=1);

use App\Services\Mekari\Exceptions\MekariApiException;
use App\Services\Mekari\MekariService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-03-05 04:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('generates valid hmac authorization header for GET requests', function (): void {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['items' => []]], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://api.mekari.com',
        'http_errors' => false,
    ]);

    $service = new MekariService(
        httpClient: $client,
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
        baseUrl: 'https://api.mekari.com',
    );

    $response = $service->request('GET', '/v2/jurnal/products');

    expect($response)->toBeArray()->toHaveKey('data');
    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];
    $dateHeader = $request->getHeaderLine('Date');

    $payload = "date: {$dateHeader}\nGET /v2/jurnal/products HTTP/1.1";
    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, 'test-client-secret', true));
    $expectedAuthorization = sprintf(
        'hmac username="%s", algorithm="hmac-sha256", headers="date request-line", signature="%s"',
        'test-client-id',
        $expectedSignature
    );

    expect($dateHeader)->toBe(CarbonImmutable::now('GMT')->toRfc7231String());
    expect($request->getHeaderLine('Authorization'))->toBe($expectedAuthorization);
});

it('adds digest header for JSON body and signs query string', function (): void {
    $history = [];
    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['id' => 'JRN-123']], JSON_THROW_ON_ERROR)),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new Client([
        'handler' => $stack,
        'base_uri' => 'https://api.mekari.com',
        'http_errors' => false,
    ]);

    $service = new MekariService(
        httpClient: $client,
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
        baseUrl: 'https://api.mekari.com',
    );

    $body = [
        'name' => 'Test Product',
        'price' => 250000,
    ];

    $service->request('POST', '/v2/jurnal/products?foo=bar', [
        'body' => $body,
        'query' => ['page' => 2],
    ]);

    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];
    $dateHeader = $request->getHeaderLine('Date');
    $payload = "date: {$dateHeader}\nPOST /v2/jurnal/products?foo=bar&page=2 HTTP/1.1";
    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, 'test-client-secret', true));

    $expectedDigest = 'SHA-256='.base64_encode(
        hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true)
    );

    expect($request->getHeaderLine('Authorization'))->toContain($expectedSignature);
    expect($request->getHeaderLine('Digest'))->toBe($expectedDigest);
});

it('throws MekariApiException for API error responses', function (): void {
    $mock = new MockHandler([
        new Response(422, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'Invalid payload',
            'errors' => ['name' => ['The name field is required.']],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $client = new Client([
        'handler' => HandlerStack::create($mock),
        'base_uri' => 'https://api.mekari.com',
        'http_errors' => false,
    ]);

    $service = new MekariService(
        httpClient: $client,
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
        baseUrl: 'https://api.mekari.com',
    );

    try {
        $service->request('POST', '/v2/jurnal/products', [
            'body' => ['name' => ''],
        ]);

        test()->fail('Expected MekariApiException was not thrown.');
    } catch (MekariApiException $exception) {
        expect($exception->getStatusCode())->toBe(422);
        expect($exception->getMessage())->toBe('Invalid payload');
        expect($exception->getResponseBody())->toBeArray()->toHaveKey('errors');
    }
});

it('wraps connection errors into MekariApiException', function (): void {
    $mock = new MockHandler([
        new ConnectException('Connection refused.', new Request('GET', '/v2/jurnal/products')),
    ]);

    $client = new Client([
        'handler' => HandlerStack::create($mock),
        'base_uri' => 'https://api.mekari.com',
        'http_errors' => false,
    ]);

    $service = new MekariService(
        httpClient: $client,
        clientId: 'test-client-id',
        clientSecret: 'test-client-secret',
        baseUrl: 'https://api.mekari.com',
    );

    try {
        $service->request('GET', '/v2/jurnal/products');
        test()->fail('Expected MekariApiException was not thrown.');
    } catch (MekariApiException $exception) {
        expect($exception->getStatusCode())->toBe(0);
        expect($exception->getMessage())->toBe('Failed to connect to Mekari API.');
    }
});
