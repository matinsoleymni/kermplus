<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\TelegramReportReasonKeyboard;
use SergiX44\Nutgram\Nutgram;

class TelegramReportReasonHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\n🗣 دلیل ریپورت رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: TelegramReportReasonKeyboard::make());
    }
}
