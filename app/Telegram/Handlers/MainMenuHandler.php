<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\MainMenuKeyboard;
use Throwable;
use SergiX44\Nutgram\Nutgram;

class MainMenuHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->setUserData('conversation', null);
        $bot->setUserData('step', null);

        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;
        $hasActiveSubscription = $local
            ? app(SubscriptionService::class)->hasActiveSubscription($local)
            : false;

        $message = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> <b>کرم پلاس</b> <tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji>\n\nاگه کسی اذیتت کرده ...\nبا ربات ما توهم می‌تونی حسابی اذیتش کنی :)\n\n<tg-emoji emoji-id='4927295007204836791'>🪱</tg-emoji> برای شروع یکی از گزینه‌های زیر رو انتخاب کن:";
        $keyboard = MainMenuKeyboard::make($hasActiveSubscription);

        try {
            $bot->editMessageText($message, parse_mode: 'HTML', reply_markup: $keyboard);
        } catch (Throwable $e) {
            logger()->warning('Failed to edit main menu message, sending a new message instead.', [
                'error' => $e->getMessage(),
            ]);
            $bot->sendMessage($message, parse_mode: 'HTML', reply_markup: $keyboard);
        }
    }
}
