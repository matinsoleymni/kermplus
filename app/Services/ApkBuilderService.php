<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
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
     * مرحله ۱: درخواست بیلد APK و دریافت اطلاعات (JSON)
     * خروجی شامل: user_id, file, download_url, status
     */
    public function generateApk(string $userId, string $token): array
    {
        // استفاده از POST طبق توضیحات سرویس برای ارسال دیتای ساختاریافته
        $response = Http::timeout(120)
            ->post("{$this->baseUrl}/generate", [
                'user_id' => $userId,
                'token'   => $token,
            ]);

        if ($response->failed()) {
            Log::error('APK Generation Request Failed:', [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);

            throw new Exception('خطا در درخواست ساخت فایل APK: کد ' . $response->status());
        }

        return $response->json();
    }

    /**
     * مرحله ۲ (اختیاری): دانلود فایل APK از URL داده شده در خروجی مرحله قبل
     * در صورتی استفاده می‌شود که نخواهید لینک دانلود مستقیم Go را به کاربر بدهید.
     */
    public function downloadApkFromServer(string $downloadUrl): string
    {
        $tempPath = storage_path('app/apks/' . Str::random(10) . '_app.apk');

        // ایجاد پوشه در صورت عدم وجود
        if (!file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $response = Http::withoutVerifying()
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
            ->timeout(120)
            ->sink($tempPath)
            ->get($downloadUrl);

        if ($response->failed()) {
            @unlink($tempPath);
            Log::error('APK Download Failed:', [
                'url'    => $downloadUrl,
                'status' => $response->status(),
            ]);

            throw new Exception('خطا در دانلود فایل APK از سرور بیلد.');
        }

        return $tempPath;
    }
}
