<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Nutgram\Laravel\Facades\Telegram;

class ProcessApkScanResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $file = $this->payload['file']; // مثال: user_123_1723276800.apk
        $status = $this->payload['status'];
        $verdict = $this->payload['verdict'] ?? 'unknown';
        $error = $this->payload['error'] ?? null;
        $permalink = $this->payload['permalink'] ?? null;

        if ($error) {
            Log::error("APK Scan Failed from Webhook", ['file' => $file, 'error' => $error]);
            return;
        }

        if (!preg_match('/^user_(.+?)_\d+\.apk$/', $file, $matches)) {
            Log::warning("فرمت نام فایل برای استخراج کاربر نامعتبر است", ['file' => $file]);
            return;
        }

        $userId = ltrim($matches[1], 'u');

        $user = User::find($userId);

        if (!$user) {
            Log::warning("کاربر مربوط به فایل APK پیدا نشد", ['user_id' => $userId, 'file' => $file]);
            return;
        }

        if ($verdict === 'malicious') {
            Log::alert("فایل مخرب شناسایی شد! مسدودسازی کاربر...", [
                'user_id' => $user->id,
                'file' => $file,
                'permalink' => $permalink
            ]);

        } elseif ($verdict === 'clean' && $status === 'completed') {
            Log::info("فایل سالم است", ['user_id' => $user->id, 'file' => $file]);

            Telegram::sendMessage($user->telegram_id, "اپ شما با موفقیت ساحته شد");

        } elseif ($status === 'timeout' || $status === 'queued') {
            Log::info("اسکن فایل در صف ماند یا تایم‌اوت شد", ['file' => $file]);
        }
    }
}
