<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Fcm\FakeFcmSender;
use App\Services\Fcm\FcmSender;

beforeEach(function () {
    $this->fcm = new FakeFcmSender;
    $this->app->instance(FcmSender::class, $this->fcm);
});

it('broadcasts an event to all of the owner devices', function () {
    $owner = User::factory()->create();
    Device::factory()->count(3)->for($owner)->create();

    $response = $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', [
            'event' => 'message',
            'data' => 'سلام و درود بر شما',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.event', 'message')
        ->assertJsonPath('data.targeted_count', 3)
        ->assertJsonPath('data.success_count', 3);

    expect($this->fcm->sent)->toHaveCount(3);
    expect($this->fcm->sent[0]['data']['event'])->toBe('message');
    expect($this->fcm->sent[0]['data']['data'])->toBe('سلام و درود بر شما');
    // Each message carries the delivery id the app sends back to acknowledge it.
    expect($this->fcm->sent[0]['data']['delivery_id'])->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('dispatched_events', [
        'user_id' => $owner->id,
        'event' => 'message',
        'success_count' => 3,
    ]);
});

it('targets a single device when device_id is given', function () {
    $owner = User::factory()->create();
    $devices = Device::factory()->count(3)->for($owner)->create();
    $target = $devices->first();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', [
            'event' => 'ping',
            'data' => ['foo' => 'bar'],
            'device_id' => $target->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.targeted_count', 1);

    expect($this->fcm->sent)->toHaveCount(1);
    expect($this->fcm->sent[0]['token'])->toBe($target->fcm_token);
    // Structured payloads are JSON-encoded into the FCM data frame.
    expect($this->fcm->sent[0]['data']['data'])->toBe('{"foo":"bar"}');
});

it('never sends to another owner devices', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Device::factory()->count(2)->for($owner)->create();
    $otherDevice = Device::factory()->for($other)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated()
        ->assertJsonPath('data.targeted_count', 2);

    $sentTokens = array_column($this->fcm->sent, 'token');
    expect($sentTokens)->not->toContain($otherDevice->fcm_token);
});

it('cannot target a device that belongs to another owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $otherDevice = Device::factory()->for($other)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', [
            'event' => 'message',
            'data' => 'hi',
            'device_id' => $otherDevice->id,
        ])
        ->assertNotFound();

    expect($this->fcm->sent)->toBeEmpty();
});

it('reports partial failures from FCM', function () {
    $owner = User::factory()->create();
    $devices = Device::factory()->count(3)->for($owner)->create();
    $this->fcm->failTokens = [$devices->first()->fcm_token];

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated()
        ->assertJsonPath('data.success_count', 2)
        ->assertJsonPath('data.failure_count', 1);
});

it('rejects sending an event without a valid api_token', function () {
    $this->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertUnauthorized();

    expect($this->fcm->sent)->toBeEmpty();
});

it('requires an event name', function () {
    $owner = User::factory()->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['data' => 'hi'])
        ->assertStatus(422);
});
