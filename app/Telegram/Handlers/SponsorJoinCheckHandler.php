<?php

namespace App\Telegram\Handlers;

use App\Telegram\Commands\StartCommand;
use SergiX44\Nutgram\Nutgram;

class SponsorJoinCheckHandler
{
    public function __invoke(Nutgram $bot): void
    {
        if ($bot->callbackQuery()) {
            $bot->answerCallbackQuery();
        }

        app(StartCommand::class)->handle($bot);
    }
}
