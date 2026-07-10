<?php

namespace App\Telegram\Handlers;

use App\Models\UsageRecord;
use App\Models\User;
use App\Telegram\Keyboards\UserStatsKeyboard;
use SergiX44\Nutgram\Nutgram;

class UserStatsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $todaySms = UsageRecord::where('user_id', $local->id)
            ->where('type', 'sms')
            ->whereDate('created_at', now()->toDateString())
            ->sum('count');

        $todayEmail = UsageRecord::where('user_id', $local->id)
            ->where('type', 'email')
            ->whereDate('created_at', now()->toDateString())
            ->sum('count');

        $totalSms = UsageRecord::where('user_id', $local->id)
            ->where('type', 'sms')
            ->sum('count');

        $totalEmail = UsageRecord::where('user_id', $local->id)
            ->where('type', 'email')
            ->sum('count');

        $msg = "📊 **آمار استفاده‌ی شما:**\n\n";
        $msg .= "📅 **امروز:**\n";
        $msg .= "📈 **کل:**\n";
        $bot->editMessageText($msg, reply_markup: UserStatsKeyboard::make());
    }
}
