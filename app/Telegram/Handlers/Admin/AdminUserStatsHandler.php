<?php

namespace App\Telegram\Handlers\Admin;

use App\Helpers\AdminStatsHelper;
use SergiX44\Nutgram\Nutgram;

class AdminUserStatsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $total = AdminStatsHelper::totalUsers();
        $daily = AdminStatsHelper::dailyActiveUsers();
        $weekly = AdminStatsHelper::weeklyActiveUsers();
        $monthly = AdminStatsHelper::monthlyActiveUsers();

        $msg = "👥 آمار کاربران:\n";
        $msg .= "👤 کل کاربران: {$total}\n";
        $msg .= "🟢 فعال روزانه: {$daily}\n";
        $msg .= "🔵 فعال هفتگی: {$weekly}\n";
        $msg .= "🟣 فعال ماهانه: {$monthly}";

        $bot->sendMessage($msg);
    }
}
