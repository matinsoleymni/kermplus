<?php

use App\Models\Device;
use App\Models\User;

it('lists only the authenticated owner devices', function () {
    $owner = User::factory()->create();
    Device::factory()->count(2)->for($owner)->create();

    $other = User::factory()->create();
    Device::factory()->count(3)->for($other)->create();

    $response = $this->withToken($owner->api_token)
        ->getJson('/api/bot/devices');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('does not expose owner credentials in the device listing', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create();

    $response = $this->withToken($owner->api_token)->getJson('/api/bot/devices');

    expect($response->json('data.0'))->not->toHaveKeys(['api_token', 'app_key', 'fcm_token']);
});
