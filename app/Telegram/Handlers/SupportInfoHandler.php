<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\BackToMainKeyboard;
use SergiX44\Nutgram\Nutgram;

class SupportInfoHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "📞 **پشتیبانی**\n\n";
        $msg .= "برای تماس با تیم پشتیبانی:\n\n";
        $msg .= "@kermsup";

        $bot->editMessageText($msg, reply_markup: BackToMainKeyboard::make());
    }
}
