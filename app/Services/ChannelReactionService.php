<?php

namespace App\Services;

use App\Models\User;
use App\Services\FeatureLimitService;
use Illuminate\Support\Facades\Http;

class ChannelReactionService
{
    protected string $apiUrl;
    protected ?string $apiToken;

    public function __construct()
    {
        $this->apiUrl = rtrim((string)config('services.channel_reaction.url'), '/');
        $this->apiToken = config('services.channel_reaction.token');
    }

    /**
     * ثبت کانال‌ها در سرویس ری‌اکشن تلگرام
     * @param array $links
     * @return array
     */
    public function addChannels(array $links): array
    {
        if (empty($this->apiUrl)) {
            return ['error' => 'Channel reaction service URL is not configured.'];
        }

        $response = $this->http()->post($this->apiUrl . '/channels', [
            'links' => $links,
        ]);

        if ($response->failed()) {
            return [
                'error' => 'Channel registration request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        return $response->json() ?? [];
    }

    /**
     * ارسال ری‌اکشن به یک پست
     * @param User $user
     * @param string $postLink
     * @param string|null $emoji
     * @return array
     */
    public function sendReaction(User $user, string $postLink, ?string $emoji = null): array
    {
        $limiter = app(FeatureLimitService::class);
        $limit = $limiter->checkNegativeReactionLimit($user);
        if ($limit) {
            return ['error' => $limit];
        }

        if (empty($this->apiUrl)) {
            return ['error' => 'Channel reaction service URL is not configured.'];
        }

        $payload = ['link' => $postLink];
        if ($emoji !== null) {
            $payload['emoji'] = $emoji;
        }

        $response = $this->http()->post($this->apiUrl . '/reactions', $payload);

        if ($response->failed()) {
            return [
                'error' => 'Channel reaction request failed.',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $limiter->recordNegativeReaction($user);

        return $response->json() ?? [];
    }

    protected function http()
    {
        $headers = [];
        if (!empty($this->apiToken)) {
            $headers['Authorization'] = 'Bearer ' . $this->apiToken;
        }

        return Http::withHeaders($headers);
    }
}
