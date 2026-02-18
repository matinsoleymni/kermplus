<?php

namespace App\Telegram\Handlers;

use App\Telegram\Commands\StartCommand;
use App\Telegram\Keyboards\BackToMainKeyboard;
use Exception;
use SergiX44\Nutgram\Nutgram;

class NotImplementedHandler
{
    public function __invoke(Nutgram $bot): void
    {
        // $text = trim((string) ($bot->message()?->text ?? ''));
        // if (str_starts_with($text, '/start')) {
        //     app(StartCommand::class)->handle($bot);
        //     return;
        // }

        try {
            $bot->editMessageText('🔧 این گزینه فعلا فعال نیست.', reply_markup: BackToMainKeyboard::make());
        } catch (Exception $e) {
            $bot->sendMessage('🔧 این گزینه فعلا فعال نیست.', reply_markup: BackToMainKeyboard::make());
            logger()->error('Error in NotImplementedHandler: ' . $e->getMessage());
        }
    }
}
