<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = Admin::factory()->create([
        'role' => 'superadmin',
    ]);
});

test('unauthenticated user cannot access admin category endpoints', function (): void {
    $response = $this->postJson('/api/v1/admin/categories', [
        'name' => 'No Auth Category',
        'min_margin' => 12,
        'fees' => json_encode(['marketplace' => ['components' => []]], JSON_THROW_ON_ERROR),
    ]);

    $response
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});

test('unauthorized user without admin model cannot access admin category endpoints', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $response = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Invalid Actor',
        'min_margin' => 10,
        'fees' => json_encode(['marketplace' => ['components' => []]], JSON_THROW_ON_ERROR),
    ]);

    $response
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});

test('authenticated admin can access admin category endpoints', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->getJson('/api/v1/admin/categories/stats/overview');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['success', 'data']);
});

test('can list all categories', function (): void {
    Category::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/categories');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'min_margin'],
            ],
            'count',
        ]);
});

test('can get single category', function (): void {
    $category = Category::factory()->create();

    $response = $this->getJson("/api/v1/categories/{$category->id}");

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonPath('data.name', $category->name);
});

test('returns 404 for non existent category', function (): void {
    $response = $this->getJson('/api/v1/categories/'.(string) Str::uuid());

    $response
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Category not found');
});

test('can create category with valid data', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $payload = [
        'name' => 'Smart Devices',
        'min_margin' => 15.5,
        'fees' => json_encode([
            'marketplace' => ['components' => []],
            'shopee' => ['components' => []],
            'entraverse' => ['components' => []],
            'tokopedia_tiktok' => ['components' => []],
        ], JSON_THROW_ON_ERROR),
        'program_garansi' => json_encode(['type' => 'extended'], JSON_THROW_ON_ERROR),
    ];

    $response = $this->postJson('/api/v1/admin/categories', $payload);

    $response
        ->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category created successfully')
        ->assertJsonPath('data.name', 'Smart Devices');

    $this->assertDatabaseHas('categories', [
        'name' => 'Smart Devices',
    ]);
});

test('cannot create category with invalid data', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->postJson('/api/v1/admin/categories', [
        'name' => '',
        'min_margin' => 200,
        'fees' => 'invalid-json',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['success', 'errors']);
});

test('can update existing category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $category = Category::factory()->create([
        'name' => 'Old Category',
        'min_margin' => 10,
    ]);

    $response = $this->putJson("/api/v1/admin/categories/{$category->id}", [
        'name' => 'Updated Category',
        'min_margin' => 22.5,
        'fees' => json_encode([
            'marketplace' => ['components' => []],
            'shopee' => ['components' => []],
            'entraverse' => ['components' => []],
            'tokopedia_tiktok' => ['components' => []],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category updated successfully')
        ->assertJsonPath('data.name', 'Updated Category');

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Category',
    ]);
});

test('cannot update nonexistent category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->putJson('/api/v1/admin/categories/'.(string) Str::uuid(), [
        'name' => 'New Name',
    ]);

    $response
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Category not found');
});

test('can soft delete category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $category = Category::factory()->create();

    $response = $this->deleteJson("/api/v1/admin/categories/{$category->id}");

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category deleted successfully');

    $this->assertSoftDeleted('categories', ['id' => $category->id]);
});

test('can restore soft deleted category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $category = Category::factory()->create();
    $category->delete();

    $response = $this->postJson("/api/v1/admin/categories/{$category->id}/restore");

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category restored successfully');

    expect(Category::withTrashed()->find($category->id)?->deleted_at)->toBeNull();
});

test('can force delete category', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $category = Category::factory()->create();
    $category->delete();

    $response = $this->deleteJson("/api/v1/admin/categories/{$category->id}/force");

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category permanently deleted successfully');

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('can get category statistics', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    Category::factory()->count(2)->create(['icon' => null]);
    Category::factory()->create(['icon' => '/storage/categories/icons/icon.svg']);
    $deleted = Category::factory()->create();
    $deleted->delete();

    $response = $this->getJson('/api/v1/admin/categories/stats/overview');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'total',
                'active',
                'deleted',
                'with_icon',
                'avg_margin',
                'max_margin',
                'min_margin',
            ],
        ]);
});

test('can bulk delete categories', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $categories = Category::factory()->count(3)->create();

    $response = $this->postJson('/api/v1/admin/categories/bulk/delete', [
        'ids' => $categories->pluck('id')->all(),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true);

    foreach ($categories as $category) {
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }
});

test('can check category name availability', function (): void {
    Sanctum::actingAs($this->admin, ['*']);
    $category = Category::factory()->create(['name' => 'Gaming Gear']);

    $existingResponse = $this->postJson('/api/v1/admin/categories/check/name', [
        'name' => 'Gaming Gear',
    ]);

    $existingResponse
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', true);

    $excludeSelfResponse = $this->postJson('/api/v1/admin/categories/check/name', [
        'name' => 'Gaming Gear',
        'exclude_id' => $category->id,
    ]);

    $excludeSelfResponse
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exists', false)
        ->assertJsonPath('data.message', 'Name available');
});
