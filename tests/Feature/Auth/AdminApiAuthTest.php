<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Admin::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'role' => 'superadmin',
    ]);
});

test('admin can login via api', function (): void {
    $response = $this->postJson('/api/v1/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'token',
                'token_type',
                'admin',
            ],
        ]);
});

test('admin cannot login with invalid credentials', function (): void {
    $response = $this->postJson('/api/v1/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Email atau password tidak valid.');
});

test('admin cannot login with invalid email format', function (): void {
    $response = $this->postJson('/api/v1/admin/login', [
        'email' => 'not-an-email',
        'password' => 'password',
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
