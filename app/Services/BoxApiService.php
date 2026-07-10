<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BoxApiService
{
    protected string $baseUrl = 'https://boxapi.ir/api';
    protected string $username;
    protected string $password;

    public function __construct()
    {
        // دریافت اطلاعات لاگین از کانفیگ طبق کد قبلی خودت
        $this->username = config('services.boxapi.username');
        $this->password = config('services.boxapi.password');
    }

    /**
     * تنظیم کلاینت HTTP با احراز هویت
     */
    protected function client()
    {
        return Http::withBasicAuth($this->username, $this->password)
                   ->baseUrl($this->baseUrl);
    }

    /**
     * دریافت اطلاعات پروفایل اینستاگرام
     */
    public function getInstagramProfile(string $username): ?array
    {
        $response = $this->client()->post('/instagram/user/get_web_profile_info', [
            'username' => $username,
        ]);

        if (!$response->ok()) {
            return null;
        }

        $user = $response->json('response.body.data.user');

        return is_array($user) ? $user : null;
    }

    /**
     * دریافت اطلاعات پست/مدیا اینستاگرام با شورت‌کد
     */
    public function getInstagramMediaInfo(string $shortcode): ?array
    {
        $response = $this->client()->post('/instagram/media/get_info_by_shortcode', [
            'shortcode' => $shortcode,
        ]);

        if (!$response->ok()) {
            return null;
        }

        $media = $response->json('response.body.items.0')
            ?? $response->json('response.body.media')
            ?? $response->json('response.body.data')
            ?? $response->json();

        return is_array($media) ? $media : null;
    }
}
