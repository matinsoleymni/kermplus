<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\ReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class ReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش ریپورتر <tg-emoji emoji-id='5334882760735598374'>📝</tg-emoji> خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: ReporterMenuKeyboard::make());
    }
}
