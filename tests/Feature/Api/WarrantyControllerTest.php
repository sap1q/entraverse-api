<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Product;
use App\Models\Warranty;
use Laravel\Sanctum\Sanctum;

test('public lookup returns warranty data with synced product image', function (): void {
    $product = Product::factory()->create([
        'photos' => [
            ['url' => 'rayban-meta.png', 'is_primary' => true],
        ],
        'name' => 'Kacamata Pintar Ray-Ban Meta Wayfarer Medium',
    ]);

    $warranty = Warranty::query()->create([
        'product_id' => (string) $product->id,
        'customer_name' => 'Budi Santoso',
        'phone' => '08123456789',
        'address' => 'Jakarta Selatan',
        'invoice_number' => 'INV-0001',
        'serial_number' => 'SN-0001',
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
    ]);

    $response = $this->postJson('/api/v1/warranties/lookup', [
        'invoice_number' => 'inv-0001',
        'serial_number' => 'sn-0001',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', (string) $warranty->id)
        ->assertJsonPath('data.product.id', (string) $product->id)
        ->assertJsonPath('data.product.name', 'Kacamata Pintar Ray-Ban Meta Wayfarer Medium')
        ->assertJsonPath('data.product.main_image', url('/storage/products/rayban-meta.png'));
});

test('admin can create and list warranties', function (): void {
    $admin = Admin::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $product = Product::factory()->create([
        'name' => 'Ray-Ban Meta Skyler',
    ]);

    $storeResponse = $this->postJson('/api/v1/admin/warranties', [
        'product_id' => (string) $product->id,
        'customer_name' => 'Sinta Wijaya',
        'phone' => '082200001111',
        'address' => 'Bandung',
        'invoice_number' => 'INV-ADMIN-1',
        'serial_number' => 'SERIAL-ADMIN-1',
        'start_date' => '2026-03-01',
        'end_date' => '2027-03-01',
    ]);

    $storeResponse
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.product_id', (string) $product->id);

    $listResponse = $this->getJson('/api/v1/admin/warranties?search=INV-ADMIN-1');

    $listResponse
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_number', 'INV-ADMIN-1')
        ->assertJsonPath('data.0.product.name', 'Ray-Ban Meta Skyler');
});
