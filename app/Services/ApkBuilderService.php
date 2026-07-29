<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ApkBuilderService
{
    protected string $baseUrl;

    public function __construct()
    {
        // آدرس سرویس Go رو از کانفیگ می‌خونیم
        $this->baseUrl = rtrim(config('services.apk_builder.base_url'), '/');
    }

    /**
     * درخواست بیلد APK و ذخیره فایل در مسیر موقت
     */
    public function downloadApk(string $userId, string $token): string
    {
        $tempPath = storage_path('app/' . \Illuminate\Support\Str::random(10) . '_app.apk');

        $response = Http::withoutVerifying()
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
            ->timeout(120)
            ->sink($tempPath)
            ->get("{$this->baseUrl}/generate", [
                'user_id' => $userId,
                'token'   => $token,
            ]);

        if ($response->failed()) {
            $errorMessage = 'خطای نامشخص';

            // اگر فایل ساخته شده، یعنی سرور یک جوابی داده (احتمالا ارور 4xx یا 5xx)
            if (file_exists($tempPath)) {
                // خواندن محتوای فایل (که احتمالا متن ارور سرور است، نه فایل باینری)
                $errorMessage = file_get_contents($tempPath);
                @unlink($tempPath);
            }

            // لاگ کردن ارور برای اینکه بتوانید آن را بررسی کنید
            \Illuminate\Support\Facades\Log::error('APK Download Failed:', [
                'status' => $response->status(),
                'body'   => $errorMessage
            ]);

            throw new \Exception('خطا در دریافت فایل APK: کد ' . $response->status());
        }

        return $tempPath;
    }
}
