<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\InstagramReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class InstagramReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='5364310996179503764'>📸</tg-emoji> ریپورتر اینستاگرام\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: InstagramReporterMenuKeyboard::make());
    }
}
