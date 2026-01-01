<?php

namespace App\Telegram\Handlers\Admin;

use SergiX44\Nutgram\Nutgram;

class AdminExitHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage('✅ از پنل ادمین خارج شدید.');
    }
}
