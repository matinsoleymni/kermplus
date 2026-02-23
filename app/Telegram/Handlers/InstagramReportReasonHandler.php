<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\InstagramReportReasonKeyboard;
use SergiX44\Nutgram\Nutgram;

class InstagramReportReasonHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $msg = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> کرم پلاس <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\n<tg-emoji emoji-id='4904973211763999824'>🗣️</tg-emoji> دلیل ریپورت رو انتخاب کن :";

        $bot->editMessageText($msg, parse_mode: 'HTML', reply_markup: InstagramReportReasonKeyboard::make());
    }
}
