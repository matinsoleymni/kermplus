<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('deactivates the owner app build', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => false])
        ->assertOk()
        ->assertExactJson(['active' => false]);

    expect($owner->fresh()->is_app_active)->toBeFalse();
});

it('reactivates the owner app build', function () {
    $owner = User::factory()->inactiveApp()->create();

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => true])
        ->assertOk()
        ->assertExactJson(['active' => true]);

    expect($owner->fresh()->is_app_active)->toBeTrue();
});

it('refreshes the cached flag so the app sees the new value', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation/is-active', ['app_key' => $owner->app_key])
        ->assertExactJson(['active' => true]);

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => false]);

    $this->postJson('/api/app/activation/is-active', ['app_key' => $owner->app_key])
        ->assertExactJson(['active' => false]);
});

it('caches the flag under the owner app key', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => false]);

    expect(Cache::get(User::appActivationCacheKey($owner->app_key)))->toBeFalse();
});

it('reports the current flag', function () {
    $owner = User::factory()->inactiveApp()->create();

    $this->getJson('/api/app/activation', ['X-App-Key' => $owner->app_key])
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

it('requires a boolean value', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => 'maybe'])
        ->assertJsonValidationErrors('active');
});

it('rejects an unknown app key', function () {
    $this->postJson('/api/app/activation', ['app_key' => 'not-a-real-key', 'active' => false])
        ->assertUnauthorized();
});

it('rejects a caller without an app key', function () {
    $this->postJson('/api/app/activation', ['active' => false])->assertUnauthorized();
});

it('leaves other owners untouched', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->postJson('/api/app/activation', ['app_key' => $owner->app_key, 'active' => false]);

    $this->postJson('/api/app/activation/is-active', ['app_key' => $other->app_key])
        ->assertExactJson(['active' => true]);
});
