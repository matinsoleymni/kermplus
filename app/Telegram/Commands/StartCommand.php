<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\StartKeyboard;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class StartCommand extends Command
{
    protected string $command = 'start';

    protected ?string $description = 'شروع ربات و نمایش منوی اصلی';

    public function handle(Nutgram $bot): void
    {
        // Always reset any pending conversation/step to avoid users getting stuck.
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);

        $tgUser = $bot->user();
        if (!$tgUser) {
            $bot->sendMessage('⛔️ خطا در دریافت اطلاعات تلگرام.');
            return;
        }

        $messageText = $bot->message()?->text ?? '';
        $referralCode = $this->extractReferralCode($messageText);
        $referrer = $referralCode
            ? User::where('referral_code', $referralCode)->first()
            : null;

        $referralService = app(ReferralService::class);
        $local = User::where('telegram_id', $tgUser->id)->first();
        if (!$local) {
            // تلگرام ایمیل نمی‌دهد؛ برای عبور از constraint ایمیل یک مقدار یکتا می‌سازیم
            $email = $tgUser->username
                ? ($tgUser->username . '@telegram.local')
                : ('tg' . $tgUser->id . '@telegram.local');

            $local = User::create([
                'telegram_id' => $tgUser->id,
                'name' => $tgUser->first_name . ' ' . ($tgUser->last_name ?? ''),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'referral_code' => $referralService->generateUniqueCode(),
                'referred_by' => $referrer?->id,
            ]);
        }

        if (!$local->referral_code) {
            $local->referral_code = $referralService->generateUniqueCode();
        }

        if (!$local->referred_by && $referrer && $referrer->id !== $local->id) {
            $local->referred_by = $referrer->id;
        }

        $local->last_active_at = now();
        $local->save();

        if ($local->isSuspended()) {
            $bot->sendMessage('⛔️ حساب شما موقتا معلق شده است. برای رفع مشکل با @kermsup تماس بگیرید.');
            return;
        }

        $hasActiveSubscription = app(SubscriptionService::class)->hasActiveSubscription($local);

        $bot->sendMessage(
            "❀ کرم پلاس ❀\n\nاگه کسی اذیتت کرده ...\nبا ربات ما توهم می‌تونی حسابی اذیتش کنی :)\n\nبرای شروع یکی از گزینه‌های زیر رو انتخاب کن:",
            reply_markup: StartKeyboard::make($hasActiveSubscription)
        );
    }

    private function extractReferralCode(string $messageText): ?string
    {
        $text = trim($messageText);
        if ($text === '' || !str_starts_with($text, '/start')) {
            return null;
        }

        // Formats we accept:
        // /start CODE
        // /start@BotName CODE
        // /start ref=CODE
        // /start ?ref=CODE
        $payload = (string) preg_replace('/^\/start(?:@\w+)?/u', '', $text, 1);
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        $payload = ltrim($payload, '?');
        $payload = urldecode($payload);

        if (str_starts_with($payload, 'ref=')) {
            $payload = substr($payload, 4);
        }

        $payload = trim($payload);

        if ($payload === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $payload)) {
            return null;
        }

        return Str::upper($payload);
    }
}
