<?php

declare(strict_types=1);

use App\Jobs\SyncProductToJurnalJob;
use App\Models\Product;
use App\Services\Mekari\Jurnal\JurnalProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.mekari.client_id', 'test-client-id');
    config()->set('services.mekari.client_secret', 'test-client-secret');
    app()->instance(JurnalProductService::class, \Mockery::mock(JurnalProductService::class));
});

afterEach(function (): void {
    \Mockery::close();
});

it('dispatches sync product job from command in queue mode', function (): void {
    Queue::fake();
    $product = Product::factory()->create();

    $this->artisan('mekari:sync-products', [
        '--id' => $product->id,
        '--queue' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SyncProductToJurnalJob::class, function (SyncProductToJurnalJob $job) use ($product): bool {
        return $job->productId === $product->id;
    });
});

it('handles successful sync in job', function (): void {
    $product = Product::factory()->create();
    $serviceMock = \Mockery::mock(JurnalProductService::class);

    $serviceMock
        ->shouldReceive('syncProduct')
        ->once()
        ->with(\Mockery::on(fn ($arg): bool => $arg instanceof Product && $arg->id === $product->id))
        ->andReturn(['data' => ['id' => 'jurnal-123']]);

    $job = new SyncProductToJurnalJob($product->id);
    $job->handle($serviceMock);

    expect(true)->toBeTrue();
});

it('throws when sync service fails so queue can retry', function (): void {
    $product = Product::factory()->create();
    $serviceMock = \Mockery::mock(JurnalProductService::class);

    $serviceMock
        ->shouldReceive('syncProduct')
        ->once()
        ->andThrow(new RuntimeException('API Error'));

    $job = new SyncProductToJurnalJob($product->id);
    $job->handle($serviceMock);
})->throws(RuntimeException::class, 'API Error');

it('has retry and backoff configuration', function (): void {
    $job = new SyncProductToJurnalJob((string) Str::uuid());

    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe([60, 180, 300, 600]);
});

it('logs failure through failed handler', function (): void {
    Log::spy();

    $job = new SyncProductToJurnalJob((string) Str::uuid());
    $job->failed(new RuntimeException('Fatal failure'));

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Queue job failed syncing product to Jurnal.', \Mockery::on(function (array $context): bool {
            return $context['error'] === 'Fatal failure';
        }));
});
