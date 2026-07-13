<?php

use App\Models\Device;
use App\Models\User;

it('registers a device under the owner resolved from the app_key', function () {
    $owner = User::factory()->create();

    $response = $this->postJson('/api/app/devices', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'fcm-token-abc',
        'manufacturer' => 'Samsung',
        'model' => 'Galaxy S24',
        'android_version' => '14',
        'sdk_int' => 34,
        'app_version' => '1.0.0',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.model', 'Galaxy S24')
        ->assertJsonPath('data.android_version', '14');

    $this->assertDatabaseHas('devices', [
        'user_id' => $owner->id,
        'fcm_token' => 'fcm-token-abc',
        'manufacturer' => 'Samsung',
    ]);
});

it('rejects device registration with an unknown app_key', function () {
    $this->postJson('/api/app/devices', [
        'app_key' => 'not-a-real-key',
        'fcm_token' => 'fcm-token-abc',
    ])->assertUnauthorized();

    $this->assertDatabaseCount('devices', 0);
});

it('updates the existing device when the same token re-registers', function () {
    $owner = User::factory()->create();

    $this->postJson('/api/app/devices', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'fcm-token-abc',
        'app_version' => '1.0.0',
    ])->assertCreated();

    $this->postJson('/api/app/devices', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'fcm-token-abc',
        'app_version' => '1.1.0',
    ])->assertOk();

    expect(Device::where('fcm_token', 'fcm-token-abc')->count())->toBe(1);
    $this->assertDatabaseHas('devices', [
        'fcm_token' => 'fcm-token-abc',
        'app_version' => '1.1.0',
    ]);
});

it('does not let one owner app_key register devices under another owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->postJson('/api/app/devices', [
        'app_key' => $owner->app_key,
        'fcm_token' => 'fcm-token-abc',
    ])->assertCreated();

    $this->assertDatabaseMissing('devices', ['user_id' => $other->id]);
});
