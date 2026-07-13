<?php

use App\Models\Device;
use App\Models\User;

it('updates a device fcm token when google refreshes it', function () {
    $owner = User::factory()->create();
    $device = Device::factory()->for($owner)->create(['fcm_token' => 'old-token']);

    $response = $this->postJson('/api/app/devices/token', [
        'app_key' => $owner->app_key,
        'old_fcm_token' => 'old-token',
        'fcm_token' => 'new-token',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'fcm_token' => 'new-token',
    ]);
    // No duplicate device is created on refresh.
    expect(Device::where('user_id', $owner->id)->count())->toBe(1);
});

it('returns 404 when the old token does not match any device', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create(['fcm_token' => 'old-token']);

    $this->postJson('/api/app/devices/token', [
        'app_key' => $owner->app_key,
        'old_fcm_token' => 'unknown-token',
        'fcm_token' => 'new-token',
    ])->assertNotFound();
});

it('cannot refresh a token belonging to another owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Device::factory()->for($other)->create(['fcm_token' => 'others-token']);

    $this->postJson('/api/app/devices/token', [
        'app_key' => $owner->app_key,
        'old_fcm_token' => 'others-token',
        'fcm_token' => 'new-token',
    ])->assertNotFound();

    $this->assertDatabaseHas('devices', ['fcm_token' => 'others-token']);
});

it('requires the new token to differ from the old one', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create(['fcm_token' => 'same-token']);

    $this->postJson('/api/app/devices/token', [
        'app_key' => $owner->app_key,
        'old_fcm_token' => 'same-token',
        'fcm_token' => 'same-token',
    ])->assertStatus(422);
});
