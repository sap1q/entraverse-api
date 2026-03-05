<?php

declare(strict_types=1);

use App\Jobs\SyncProductToJurnalJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Mekari\Exceptions\MekariApiException;
use App\Services\Mekari\Jurnal\JurnalProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->jurnalServiceMock = \Mockery::mock(JurnalProductService::class);
    app()->instance(JurnalProductService::class, $this->jurnalServiceMock);
});

afterEach(function (): void {
    \Mockery::close();
});

it('allows authenticated user to sync single product successfully', function (): void {
    Sanctum::actingAs($this->user);
    $product = Product::factory()->create(['jurnal_id' => null]);

    $this->jurnalServiceMock
        ->shouldReceive('syncProduct')
        ->once()
        ->with(\Mockery::type(Product::class))
        ->andReturn([
            'data' => ['id' => 'jurnal-123'],
        ]);

    $response = $this->postJson("/api/v1/integrations/jurnal/products/{$product->id}/sync");

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Product synced to Jurnal.')
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'product',
                'jurnal_response',
            ],
        ]);
});

it('rejects unauthenticated user for sync endpoint', function (): void {
    $product = Product::factory()->create();
    $response = $this->postJson("/api/v1/integrations/jurnal/products/{$product->id}/sync");
    $response->assertUnauthorized();
});

it('dispatches queue job when queue mode is requested', function (): void {
    Queue::fake();
    Sanctum::actingAs($this->user);
    $product = Product::factory()->create();

    $response = $this->postJson("/api/v1/integrations/jurnal/products/{$product->id}/sync?queue=1");

    $response
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Product sync has been queued.');

    Queue::assertPushed(SyncProductToJurnalJob::class, function (SyncProductToJurnalJob $job) use ($product): bool {
        return $job->productId === $product->id;
    });
});

it('returns 404 when product is not found', function (): void {
    Sanctum::actingAs($this->user);
    $response = $this->postJson('/api/v1/integrations/jurnal/products/00000000-0000-0000-0000-000000000000/sync');
    $response->assertNotFound();
});

it('syncs all products in batch mode', function (): void {
    Sanctum::actingAs($this->user);
    Product::factory()->count(3)->create();

    $this->jurnalServiceMock
        ->shouldReceive('syncProduct')
        ->times(3)
        ->andReturn(['data' => ['id' => 'jurnal-123']]);

    $response = $this->postJson('/api/v1/integrations/jurnal/products/sync-all');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.success_count', 3)
        ->assertJsonPath('data.failed_count', 0)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'total',
                'success_count',
                'failed_count',
                'success',
                'failed',
            ],
        ]);
});

it('queues all products when batch queue mode is enabled', function (): void {
    Queue::fake();
    Sanctum::actingAs($this->user);
    Product::factory()->count(2)->create();

    $response = $this->postJson('/api/v1/integrations/jurnal/products/sync-all', [
        'queue' => true,
    ]);

    $response
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.queued_count', 2);

    Queue::assertPushed(SyncProductToJurnalJob::class, 2);
});

it('gets products from jurnal', function (): void {
    Sanctum::actingAs($this->user);

    $mockResponse = [
        'data' => [
            ['id' => 'jurnal-1', 'name' => 'Product 1'],
            ['id' => 'jurnal-2', 'name' => 'Product 2'],
        ],
    ];

    $this->jurnalServiceMock
        ->shouldReceive('getProducts')
        ->once()
        ->with([])
        ->andReturn($mockResponse);

    $response = $this->getJson('/api/v1/integrations/jurnal/products');

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => $mockResponse,
        ]);
});

it('imports products from jurnal into local database', function (): void {
    Sanctum::actingAs($this->user);

    $this->jurnalServiceMock
        ->shouldReceive('importProductsFromJurnal')
        ->once()
        ->with([
            'page' => 1,
            'per_page' => 50,
            'include_archive' => true,
        ], 2)
        ->andReturn([
            'requested_page' => 1,
            'fetched_pages' => 1,
            'created' => 3,
            'updated' => 1,
            'failed_count' => 0,
            'failed' => [],
            'total_remote_count' => 4,
            'imported_count' => 4,
        ]);

    $response = $this->postJson('/api/v1/integrations/jurnal/products/import', [
        'max_pages' => 2,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Jurnal products imported successfully.')
        ->assertJsonPath('data.created', 3)
        ->assertJsonPath('data.updated', 1);
});

it('returns validation error for invalid product ids during batch sync', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/integrations/jurnal/products/sync-all', [
        'product_ids' => ['invalid-id'],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product_ids.0']);
});

it('returns validation error for invalid import params', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/integrations/jurnal/products/import', [
        'max_pages' => 0,
        'per_page' => 999,
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['max_pages', 'per_page']);
});

it('handles jurnal api errors gracefully on single sync', function (): void {
    Sanctum::actingAs($this->user);
    $product = Product::factory()->create();

    $this->jurnalServiceMock
        ->shouldReceive('syncProduct')
        ->once()
        ->andThrow(new MekariApiException(
            message: 'Invalid request',
            statusCode: 422,
            responseData: ['message' => 'Invalid request']
        ));

    $response = $this->postJson("/api/v1/integrations/jurnal/products/{$product->id}/sync");

    $response
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Failed to sync product to Jurnal.')
        ->assertJsonPath('error', 'Invalid request')
        ->assertJsonPath('source', 'mekari')
        ->assertJsonPath('upstream_status', 422);
});

it('maps mekari unauthorized error to bad gateway to avoid frontend logout redirect', function (): void {
    Sanctum::actingAs($this->user);
    $product = Product::factory()->create();

    $this->jurnalServiceMock
        ->shouldReceive('syncProduct')
        ->once()
        ->andThrow(new MekariApiException(
            message: 'The access token is invalid or has expired',
            statusCode: 401,
            responseData: ['error' => 'invalid_token']
        ));

    $response = $this->postJson("/api/v1/integrations/jurnal/products/{$product->id}/sync");

    $response
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Failed to sync product to Jurnal.')
        ->assertJsonPath('source', 'mekari')
        ->assertJsonPath('upstream_status', 401);
});
