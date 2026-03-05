<?php

declare(strict_types=1);

use App\Events\ProductSyncedToJurnal;
use App\Models\Category;
use App\Models\Product;
use App\Services\Mekari\Exceptions\MekariApiException;
use App\Services\Mekari\Jurnal\JurnalProductService;
use App\Services\Mekari\MekariService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.mekari.jurnal_base_path', '/public/jurnal/api/v1');
    config()->set('services.mekari.base_url', 'https://api.mekari.com');
    $this->mekariMock = \Mockery::mock(MekariService::class);
    $this->service = new JurnalProductService($this->mekariMock);
});

afterEach(function (): void {
    \Mockery::close();
});

it('transforms product to jurnal format correctly', function (): void {
    $category = Category::factory()->create(['name' => 'Electronics']);
    $product = Product::factory()
        ->for($category)
        ->withVariants(2)
        ->create([
            'name' => 'Test Product',
            'spu' => 'TEST-001',
            'brand' => 'Test Brand',
            'category' => 'Legacy Category',
            'product_status' => 'active',
            'inventory' => [
                'price' => 150000,
                'cost' => 100000,
                'total_stock' => 10,
                'weight' => 500,
            ],
        ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('transformProductToJurnal');
    $method->setAccessible(true);
    $result = $method->invoke($this->service, $product->fresh('category'));

    expect($result['name'])->toBe('Test Product');
    expect($result['product_code'])->toBe('TEST-001');
    expect($result['custom_id'])->toBe('TEST-001');
    expect($result['track_inventory'])->toBeTrue();
    expect($result['archive'])->toBeFalse();
    expect($result['weight'])->toBe(500.0);
    expect($result)->toHaveKey('sell_price_per_unit');
    expect($result)->toHaveKey('buy_price_per_unit');
});

it('creates product in jurnal and persists jurnal fields', function (): void {
    Event::fake([ProductSyncedToJurnal::class]);

    $product = Product::factory()->create([
        'jurnal_id' => null,
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with(
            'POST',
            '/public/jurnal/api/v1/products',
            \Mockery::on(fn ($options): bool => isset($options['body']['product']) && is_array($options['body']['product']))
        )
        ->andReturn([
            'product' => ['id' => 'jurnal-123'],
        ]);

    $response = $this->service->createProduct($product->fresh('category'));

    expect(data_get($response, 'product.id'))->toBe('jurnal-123');
    expect($product->fresh()->jurnal_id)->toBe('jurnal-123');
    expect($product->fresh()->last_synced_at)->not->toBeNull();
    Event::assertDispatched(ProductSyncedToJurnal::class);
});

it('updates product in jurnal when jurnal id exists', function (): void {
    Event::fake([ProductSyncedToJurnal::class]);

    $product = Product::factory()->withJurnal()->create([
        'jurnal_id' => 'jurnal-123',
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with(
            'PATCH',
            '/public/jurnal/api/v1/products/jurnal-123',
            \Mockery::on(fn ($options): bool => isset($options['body']['product']) && is_array($options['body']['product']))
        )
        ->andReturn([
            'product' => ['id' => 'jurnal-123'],
        ]);

    $response = $this->service->updateProduct($product->fresh('category'));

    expect(data_get($response, 'product.id'))->toBe('jurnal-123');
    expect($product->fresh()->jurnal_id)->toBe('jurnal-123');
    Event::assertDispatched(ProductSyncedToJurnal::class);
});

it('deletes product from jurnal and resets local jurnal fields', function (): void {
    $product = Product::factory()->withJurnal()->create([
        'jurnal_id' => 'jurnal-123',
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('DELETE', '/public/jurnal/api/v1/products/jurnal-123')
        ->andReturn([
            'success' => true,
        ]);

    $response = $this->service->deleteProduct($product);

    expect($response)->toBe(['success' => true]);
    expect($product->fresh()->jurnal_id)->toBeNull();
    expect($product->fresh()->last_synced_at)->not->toBeNull();
});

it('propagates exception from mekari service and stores failed sync status', function (): void {
    $product = Product::factory()->create();

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->andThrow(new RuntimeException('Mekari error'));

    try {
        $this->service->createProduct($product);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Mekari error');
    }

    $failedStatus = $product->fresh()->mekari_status ?? [];
    expect(data_get($failedStatus, 'sync_status'))->toBe('failed');
    expect(data_get($failedStatus, 'last_action'))->toBe('create');
    expect(data_get($failedStatus, 'last_error'))->toBe('Mekari error');
});

it('marks product as failed when minimum jurnal sync requirements are not met', function (): void {
    $product = Product::factory()->create([
        'spu' => null,
        'inventory' => [
            'price' => 0,
            'cost' => 0,
            'total_stock' => 2,
            'weight' => 100,
        ],
        'variant_pricing' => [],
    ]);

    $this->mekariMock->shouldNotReceive('request');

    try {
        $this->service->createProduct($product);
        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (\InvalidArgumentException $exception) {
        expect($exception->getMessage())->toContain('SPU/SKU kosong');
        expect($exception->getMessage())->toContain('harga jual harus lebih dari 0');
    }

    $failedStatus = $product->fresh()->mekari_status ?? [];
    expect(data_get($failedStatus, 'sync_status'))->toBe('failed');
    expect(data_get($failedStatus, 'last_action'))->toBe('create');
    expect((string) data_get($failedStatus, 'last_error'))->toContain('tidak memenuhi syarat sinkronisasi Jurnal');
});

it('imports product image using fallback image fields from jurnal payload', function (): void {
    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('GET', '/public/jurnal/api/v1/products', [
            'query' => [
                'page' => 1,
                'per_page' => 1,
            ],
        ])
        ->andReturn([
            'products' => [
                [
                    'id' => 'jrnl-img-1',
                    'name' => 'Imported Product With Image',
                    'product_code' => 'IMG-001',
                    'quantity_available' => 3,
                    'sell_price_per_unit' => 250000,
                    'buy_price_per_unit' => 180000,
                    'images' => [
                        [
                            'url' => '/images/products/img-001.jpg',
                        ],
                    ],
                ],
            ],
            'total_pages' => 1,
        ]);

    $result = $this->service->importProductsFromJurnal([
        'page' => 1,
        'per_page' => 1,
    ], 1);

    expect($result['created'])->toBe(1);
    expect($result['failed_count'])->toBe(0);

    $product = Product::query()->where('jurnal_id', 'jrnl-img-1')->first();
    expect($product)->not()->toBeNull();
    expect(data_get($product?->photos, '0.url'))->toBe('https://api.mekari.com/images/products/img-001.jpg');
    expect(data_get($product?->photos, '0.is_primary'))->toBeTrue();
});

it('syncs by updating existing remote product found by custom id', function (): void {
    Event::fake([ProductSyncedToJurnal::class]);

    $product = Product::factory()->create([
        'spu' => 'TEST-UPSERT-001',
        'jurnal_id' => null,
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('GET', '/public/jurnal/api/v1/products/TEST-UPSERT-001')
        ->andReturn([
            'product' => ['id' => 987654],
        ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with(
            'PATCH',
            '/public/jurnal/api/v1/products/987654',
            \Mockery::on(fn ($options): bool => isset($options['body']['product']) && is_array($options['body']['product']))
        )
        ->andReturn([
            'product' => ['id' => 987654],
        ]);

    $response = $this->service->syncProduct($product->fresh('category'));

    expect(data_get($response, 'product.id'))->toBe(987654);
    expect($product->fresh()->jurnal_id)->toBe('987654');
    Event::assertDispatched(ProductSyncedToJurnal::class);
});

it('syncs by creating product when custom id does not exist remotely', function (): void {
    Event::fake([ProductSyncedToJurnal::class]);

    $product = Product::factory()->create([
        'spu' => 'TEST-UPSERT-404',
        'jurnal_id' => null,
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('GET', '/public/jurnal/api/v1/products/TEST-UPSERT-404')
        ->andThrow(new MekariApiException('Not found', 404, ['error' => 'not_found']));

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with(
            'POST',
            '/public/jurnal/api/v1/products',
            \Mockery::on(fn ($options): bool => isset($options['body']['product']) && is_array($options['body']['product']))
        )
        ->andReturn([
            'product' => ['id' => 123123],
        ]);

    $response = $this->service->syncProduct($product->fresh('category'));

    expect(data_get($response, 'product.id'))->toBe(123123);
    expect($product->fresh()->jurnal_id)->toBe('123123');
    Event::assertDispatched(ProductSyncedToJurnal::class);
});

it('archives product in jurnal', function (): void {
    $product = Product::factory()->withJurnal()->create([
        'jurnal_id' => 'jurnal-archive-1',
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('POST', '/public/jurnal/api/v1/products/jurnal-archive-1/deactivate')
        ->andReturn(['success' => true]);

    $response = $this->service->archiveProduct($product);

    expect($response)->toBe(['success' => true]);
    expect(data_get($product->fresh()->mekari_status, 'sync_status'))->toBe('archived');
    expect(data_get($product->fresh()->mekari_status, 'last_action'))->toBe('archive');
});

it('unarchives product in jurnal', function (): void {
    $product = Product::factory()->withJurnal()->create([
        'jurnal_id' => 'jurnal-unarchive-1',
    ]);

    $this->mekariMock
        ->shouldReceive('request')
        ->once()
        ->with('POST', '/public/jurnal/api/v1/products/jurnal-unarchive-1/activate')
        ->andReturn(['success' => true]);

    $response = $this->service->unarchiveProduct($product);

    expect($response)->toBe(['success' => true]);
    expect(data_get($product->fresh()->mekari_status, 'sync_status'))->toBe('active');
    expect(data_get($product->fresh()->mekari_status, 'last_action'))->toBe('unarchive');
});
