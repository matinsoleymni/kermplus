<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards\BackToMainKeyboard;
use SergiX44\Nutgram\Nutgram;

class NotImplementedHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->editMessageText('🔧 این گزینه فعلا فعال نیست. برای اطلاعات بیشتر با @kermsup تماس بگیرید.', reply_markup: BackToMainKeyboard::make());
    }
}
