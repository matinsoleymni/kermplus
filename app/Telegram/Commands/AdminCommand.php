<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Telegram\Conversations\AdminPanelConversation;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class AdminCommand extends Command
{
    protected string $command = 'admin';

    protected ?string $description = 'ورود به پنل ادمین';

    public function handle(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        if (!$tgUser) {
            $bot->sendMessage('⛔️خطا در دریافت اطلاعات تلگرام.');
            return;
        }

        $local = User::where('telegram_id', $tgUser->id)->first();
        if (!$local || !$local->isAdmin()) {
            $bot->sendMessage('⛔️ شما دسترسی ادمین ندارید.');
            return;
        }

        AdminPanelConversation::begin($bot);
    }
}
