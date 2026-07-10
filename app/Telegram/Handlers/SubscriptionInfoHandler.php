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
        } else {
            $msg = "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\n";
            $msg .= "برای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";
            $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: PlusRequiredKeyboard::make('main_menu'));
            return;
        }

        $bot->editMessageText($msg, reply_markup: BackToMainKeyboard::make());
    }
}
