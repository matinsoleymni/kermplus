<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\BackToMainKeyboard;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
use SergiX44\Nutgram\Nutgram;

class SubscriptionInfoHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('⛔️ کاربر یافت نشد.');
            return;
        }

        $service = app(SubscriptionService::class);
        $details = $service->getSubscriptionDetails($local);

        if ($details['is_active']) {
            $msg = "✅ **اشتراک فعال**\n";
            $msg .= "📋 پلن: {$details['plan_name']}\n";
            $msg .= "📅 شروع: {$details['started_at']}\n";
            $msg .= "⏱️ انقضا: {$details['expires_at']}\n";
            $msg .= "📊 باقی روز: {$details['remaining_days']}\n";
            $msg .= "💬 SMS روزانه: {$details['max_sms_per_day']}\n";
            $msg .= "📧 Email روزانه: {$details['max_email_per_day']}";
        } else {
            $msg = "❗️✨ این بخش نیازمند به نسخه پلاس رباتمونه 😚\n\n";
            $msg .= "برای ارتقای نسخه ربات به \"نسخه پلاس🎗\" از طریق دکمه های زیر اقدام کنید :";
            $bot->editMessageText($msg, reply_markup: PlusRequiredKeyboard::make('main_menu'));
            return;
        }

        $bot->editMessageText($msg, reply_markup: BackToMainKeyboard::make());
    }
}
