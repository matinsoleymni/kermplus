<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class KermAppService
{
    protected string $baseUrl;
    protected string $botSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.kermapp.base_url'), '/');
        $this->botSecret = config('services.kermapp.secret');
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson();
    }

    // ثبت نام اونر در سرور اصلی
    public function registerOwner(int $telegramId, ?string $username = null, ?string $name = null): array
    {
        $payload = array_filter([
            'telegram_id' => $telegramId,
            'username'    => $username,
            'name'        => $name,
        ], fn($value) => !is_null($value));

        return $this->client()
            ->withHeader('X-Bot-Secret', $this->botSecret)
            ->post('/users', $payload)
            ->throw()
            ->json();
    }

    // تغییر هدرها به Bearer Token طبق داکیومنت فرآیند
    public function getDevices(string $apiToken, int $page = 1): array
    {
        return $this->client()
            ->withToken($apiToken) // ارسال به صورت Bearer <token>
            ->get('/devices', ['page' => $page])
            ->throw()
            ->json();
    }

    public function sendEvent(string $apiToken, string $event, mixed $data = null, ?int $deviceId = null): array
    {
        $payload = array_filter([
            'event'     => $event,
            'data'      => $data,
            'device_id' => $deviceId,
        ], fn($value) => !is_null($value));

        return $this->client()
            ->withToken($apiToken)
            ->post('/events', $payload)
            ->throw()
            ->json();
    }

    public function getEvents(string $apiToken, int $page = 1): array
    {
        return $this->client()
            ->withToken($apiToken)
            ->get('/events', ['page' => $page])
            ->throw()
            ->json();
    }

    public function getEventStatus(string $apiToken, int $eventId): array
    {
        return $this->client()
            ->withToken($apiToken)
            ->get("/events/{$eventId}")
            ->throw()
            ->json();
    }
}
