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
        $this->baseUrl = rtrim(config('services.apk_builder.base_url'), '/');
    }

    public function generateApk(string $userId, string $token): array
    {
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

    public function downloadApkFromServer(string $downloadUrl): string
    {
        $tempPath = storage_path('app/apks/' . Str::random(10) . '_app.apk');

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
