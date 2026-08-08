<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('deactivates the owner app build', function () {
    $owner = User::factory()->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/activation', ['active' => false])
        ->assertOk()
        ->assertExactJson(['active' => false]);

    expect($owner->fresh()->is_app_active)->toBeFalse();
});

it('reactivates the owner app build', function () {
    $owner = User::factory()->inactiveApp()->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/activation', ['active' => true])
        ->assertOk()
        ->assertExactJson(['active' => true]);

    expect($owner->fresh()->is_app_active)->toBeTrue();
});

it('refreshes the cached flag so the app sees the new value', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation/is-active', ['app_key' => $owner->app_key])
        ->assertExactJson(['active' => true]);

    $this->withToken($owner->api_token)->postJson('/api/bot/activation', ['active' => false]);

    $this->postJson('/api/app/activation/is-active', ['app_key' => $owner->app_key])
        ->assertExactJson(['active' => false]);
});

it('caches the flag under the owner app key', function () {
    $owner = User::factory()->create();

    $this->withToken($owner->api_token)->postJson('/api/bot/activation', ['active' => false]);

    expect(Cache::get(User::appActivationCacheKey($owner->app_key)))->toBeFalse();
});

it('reports the current flag', function () {
    $owner = User::factory()->inactiveApp()->create();

    $this->withToken($owner->api_token)
        ->getJson('/api/bot/activation')
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

it('requires a boolean value', function () {
    $owner = User::factory()->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/activation', ['active' => 'maybe'])
        ->assertJsonValidationErrors('active');
});

it('rejects an unauthenticated caller', function () {
    $this->postJson('/api/bot/activation', ['active' => false])->assertUnauthorized();
});

it('leaves other owners untouched', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->withToken($owner->api_token)->postJson('/api/bot/activation', ['active' => false]);

    $this->postJson('/api/app/activation/is-active', ['app_key' => $other->app_key])
        ->assertExactJson(['active' => true]);
});
