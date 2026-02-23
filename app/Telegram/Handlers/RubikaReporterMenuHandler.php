<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\RubikaReporterMenuKeyboard;
use SergiX44\Nutgram\Nutgram;

class RubikaReporterMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4978973209056511046'>💬</tg-emoji> ریپورتر روبیکا 🤝\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: RubikaReporterMenuKeyboard::make());
    }
}
