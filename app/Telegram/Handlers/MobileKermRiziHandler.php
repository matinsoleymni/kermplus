<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\MobileKermRiziKeyboard;
use SergiX44\Nutgram\Nutgram;

class MobileKermRiziHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nبه بخش کرم ریزی رو موبایل <tg-emoji emoji-id='5407025283456835913'>📱</tg-emoji>  خوش اومدی ✋🏻\nبرای ادامه یکی از گزینه های زیر رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: MobileKermRiziKeyboard::make());
    }
}
