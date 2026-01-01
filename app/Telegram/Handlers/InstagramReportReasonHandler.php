<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\InstagramReportReasonKeyboard;
use SergiX44\Nutgram\Nutgram;

class InstagramReportReasonHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "❀ کرم پلاس ❀\n\n🗣 دلیل ریپورت رو انتخاب کن :";

        $bot->editMessageText($msg, reply_markup: InstagramReportReasonKeyboard::make());
    }
}
