<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\SubscriptionService;
use App\Telegram\Keyboards\BackToMainKeyboard;
use App\Telegram\Keyboards\PlusRequiredKeyboard;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class NotImplementedHandler
{
    private const COMING_SOON_MESSAGE = "<tg-emoji emoji-id='4929619512224909015'>🪱</tg-emoji> این بخش بزودی در دسترستون قرار میگیره <tg-emoji emoji-id='5134654202894615343'>🪱</tg-emoji>";
    private const PLUS_REQUIRED_MESSAGE = "<tg-emoji emoji-id=\"6224077119996040131\">❗️</tg-emoji><tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> این بخش نیازمند ارتقای نسخه رباتمونه <tg-emoji emoji-id=\"5370967353674701492\">😚</tg-emoji>\n\nبرای ارتقای نسخه ربات به \"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" و یا به \"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از طریق دکمه های زیر اقدام کنید :";

    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;
        $hasActiveSubscription = $local
            ? app(SubscriptionService::class)->hasActiveSubscription($local)
            : false;

        $message = $hasActiveSubscription ? self::COMING_SOON_MESSAGE : self::PLUS_REQUIRED_MESSAGE;
        $keyboard = $hasActiveSubscription
            ? BackToMainKeyboard::make()
            : PlusRequiredKeyboard::make('main_menu');

        try {
            $bot->editMessageText(
                $message,
                parse_mode: 'HTML',
                reply_markup: $keyboard
            );
        } catch (Throwable $e) {
            $bot->sendMessage(
                $message,
                parse_mode: 'HTML',
                reply_markup: $keyboard
            );

            logger()->warning('Failed to edit not implemented message, sending a new message instead.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
