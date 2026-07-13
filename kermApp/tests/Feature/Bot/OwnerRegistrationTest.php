<?php

use App\Models\User;

it('registers an owner with the bot secret and issues unique credentials', function () {
    $response = $this->withHeader('X-Bot-Secret', 'test-bot-secret')
        ->postJson('/api/bot/users', [
            'telegram_id' => 123456789,
            'username' => 'darkmortal',
            'name' => 'Dark Mortal',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.telegram_id', 123456789)
        ->assertJsonPath('data.username', 'darkmortal');

    expect($response->json('data.api_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.app_key'))->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('users', ['telegram_id' => 123456789]);
});

it('rejects registration without the bot secret', function () {
    $this->postJson('/api/bot/users', ['telegram_id' => 1])
        ->assertUnauthorized();

    $this->assertDatabaseCount('users', 0);
});

it('gives two different owners isolated credentials', function () {
    $first = $this->withHeader('X-Bot-Secret', 'test-bot-secret')
        ->postJson('/api/bot/users', ['telegram_id' => 111])->json('data');

    $second = $this->withHeader('X-Bot-Secret', 'test-bot-secret')
        ->postJson('/api/bot/users', ['telegram_id' => 222])->json('data');

    expect($first['app_key'])->not->toBe($second['app_key']);
    expect($first['api_token'])->not->toBe($second['api_token']);
});

it('rejects a duplicate telegram_id', function () {
    User::factory()->create(['telegram_id' => 555]);

    $this->withHeader('X-Bot-Secret', 'test-bot-secret')
        ->postJson('/api/bot/users', ['telegram_id' => 555])
        ->assertStatus(422);
});
