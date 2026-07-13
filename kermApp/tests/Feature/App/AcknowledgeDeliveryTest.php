<?php

use App\Enums\DeliveryStatus;
use App\Models\Device;
use App\Models\User;
use App\Services\Fcm\FakeFcmSender;
use App\Services\Fcm\FcmSender;

beforeEach(function () {
    $this->fcm = new FakeFcmSender;
    $this->app->instance(FcmSender::class, $this->fcm);
});

it('creates a delivery per device and reports it back in the FCM payload', function () {
    $owner = User::factory()->create();
    Device::factory()->count(2)->for($owner)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated();

    $this->assertDatabaseCount('event_deliveries', 2);
    $this->assertDatabaseHas('event_deliveries', [
        'user_id' => $owner->id,
        'status' => DeliveryStatus::Sent->value,
    ]);
});

it('lets the app acknowledge a delivery using the delivery id from the payload', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated();

    $deliveryId = (int) $this->fcm->sent[0]['data']['delivery_id'];

    $response = $this->postJson("/api/app/deliveries/{$deliveryId}/ack", [
        'app_key' => $owner->app_key,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', DeliveryStatus::Acknowledged->value);

    $this->assertDatabaseHas('event_deliveries', [
        'id' => $deliveryId,
        'status' => DeliveryStatus::Acknowledged->value,
    ]);
    expect($response->json('data.acknowledged_at'))->not->toBeNull();
});

it('reflects acknowledgements in the event acknowledged_count', function () {
    $owner = User::factory()->create();
    Device::factory()->count(3)->for($owner)->create();

    $event = $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->json('data');

    expect($event['acknowledged_count'])->toBe(0);

    $deliveryId = (int) $this->fcm->sent[0]['data']['delivery_id'];
    $this->postJson("/api/app/deliveries/{$deliveryId}/ack", ['app_key' => $owner->app_key])
        ->assertOk();

    $this->withToken($owner->api_token)
        ->getJson("/api/bot/events/{$event['id']}")
        ->assertOk()
        ->assertJsonPath('data.acknowledged_count', 1);
});

it('forbids acknowledging a delivery that belongs to another owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Device::factory()->for($owner)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated();

    $deliveryId = (int) $this->fcm->sent[0]['data']['delivery_id'];

    // The other owner's app_key must not be able to ack this delivery.
    $this->postJson("/api/app/deliveries/{$deliveryId}/ack", [
        'app_key' => $other->app_key,
    ])->assertNotFound();

    $this->assertDatabaseHas('event_deliveries', [
        'id' => $deliveryId,
        'status' => DeliveryStatus::Sent->value,
    ]);
});

it('rejects acknowledgement without a valid app_key', function () {
    $owner = User::factory()->create();
    Device::factory()->for($owner)->create();

    $this->withToken($owner->api_token)
        ->postJson('/api/bot/events', ['event' => 'message', 'data' => 'hi'])
        ->assertCreated();

    $deliveryId = (int) $this->fcm->sent[0]['data']['delivery_id'];

    $this->postJson("/api/app/deliveries/{$deliveryId}/ack")
        ->assertUnauthorized();
});
