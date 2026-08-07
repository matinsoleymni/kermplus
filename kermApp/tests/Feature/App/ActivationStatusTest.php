<?php

use App\Models\User;

it('reports the app as active for a known app key', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
    ])->assertOk()->assertExactJson(['active' => true]);
});

it('accepts the app key from the header', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation/is-active', [], [
        'X-App-Key' => $owner->app_key,
    ])->assertOk()->assertExactJson(['active' => true]);
});

it('does not require the device to be registered yet', function () {
    $owner = User::factory()->create();

    expect($owner->devices()->count())->toBe(0);

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
    ])->assertOk();
});

it('rejects an unknown app key', function () {
    $this->postJson('/api/app/activation/is-active', [
        'app_key' => 'not-a-real-key',
    ])->assertUnauthorized();
});

it('requires an app key', function () {
    $this->postJson('/api/app/activation/is-active')->assertUnauthorized();
});
