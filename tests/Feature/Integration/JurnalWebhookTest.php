<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts webhook when secret is empty', function (): void {
    config()->set('services.mekari.webhook_secret', null);

    $response = $this->postJson('/api/v1/integrations/jurnal/webhook', [
        'event' => 'product.updated',
        'data' => ['id' => 'jurnal-123'],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('rejects webhook with invalid signature when secret configured', function (): void {
    config()->set('services.mekari.webhook_secret', 'test-webhook-secret');

    $response = $this->postJson('/api/v1/integrations/jurnal/webhook', [
        'event' => 'product.updated',
    ], [
        'X-Mekari-Signature' => 'sha256=invalid',
    ]);

    $response
        ->assertStatus(401)
        ->assertJsonPath('success', false);
});

it('accepts webhook with valid signature when secret configured', function (): void {
    $secret = 'test-webhook-secret';
    config()->set('services.mekari.webhook_secret', $secret);

    $payload = [
        'event' => 'product.updated',
        'data' => ['id' => 'jurnal-123'],
    ];

    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $rawBody, $secret);

    $response = $this->call(
        'POST',
        '/api/v1/integrations/jurnal/webhook',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MEKARI_SIGNATURE' => "sha256={$signature}",
        ],
        $rawBody
    );

    $response
        ->assertOk()
        ->assertJsonPath('success', true);
});
