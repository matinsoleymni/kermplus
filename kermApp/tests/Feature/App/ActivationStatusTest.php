<?php

use App\Models\Device;
use App\Models\User;

it('reports the device as active', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create(['fcm_token' => 'device-token']);

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'device-token',
    ])->assertOk()->assertExactJson(['active' => true]);
});

it('returns 404 when the token does not match any device', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create(['fcm_token' => 'device-token']);

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'unknown-token',
    ])->assertNotFound();
});

it('cannot check a device belonging to another owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Device::factory()->for($other)->create(['fcm_token' => 'others-token']);

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'others-token',
    ])->assertNotFound();
});

it('requires an app key', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create(['fcm_token' => 'device-token']);

    $this->postJson('/api/app/activation/is-active', [
        'fcm_token' => 'device-token',
    ])->assertUnauthorized();
});

it('requires an fcm token', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/activation/is-active', [
        'app_key' => $owner->app_key,
    ])->assertStatus(422);
});
