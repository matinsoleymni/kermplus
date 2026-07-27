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
        $tempPath = sys_get_temp_dir() . '/' . Str::random(10) . '_app.apk';

        $response = Http::timeout(120)
            ->sink($tempPath)
            ->get("{$this->baseUrl}/generate", [
                'user_id' => $userId,
                'token'   => $token,
            ]);

        if ($response->failed()) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new \Exception('خطا در دریافت فایل APK از سرویس سازنده.');
        }

        return $tempPath;
    }
}
