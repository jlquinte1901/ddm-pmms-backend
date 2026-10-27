<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('an unauthenticated API request returns the stable error contract', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertHeader('X-Request-ID')
        ->assertJsonPath('code', 'unauthenticated')
        ->assertJsonPath('message', 'Unauthenticated.')
        ->assertJsonPath('errors', []);
});

test('an authorization failure returns a forbidden error contract', function () {
    seedAccessControl();
    $viewer = User::factory()->viewer()->create();
    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden')
        ->assertJsonPath('message', 'This action is unauthorized.')
        ->assertJsonPath('errors', []);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $viewer->id,
        'module' => 'shared',
        'action' => 'authorization_denied',
    ]);
});

test('missing records and routes return a not-found error contract', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/users/999999')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('errors', []);

    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

test('validation failures expose field errors', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure([
            'message',
            'code',
            'errors' => ['email', 'password'],
        ]);
});

test('unsupported methods return a method-not-allowed error contract', function () {
    $this->putJson('/api/v1/auth/me')
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed')
        ->assertJsonPath('errors', []);
});
