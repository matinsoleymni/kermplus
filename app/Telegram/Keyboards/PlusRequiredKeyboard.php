<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PlusRequiredKeyboard
{
    public static function make(?string $backCallback = null): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('ارتقا ربات', callback_data: 'buy_subscription', style: 'danger', icon: '4927295007204836791'))
            ->addRow(
                InlineKeyboardButton::make('نسخه پرو چیه؟', url: 'https://t.me/kermpluslearn/19', style: 'danger', icon: '6244241334320762892'),
                InlineKeyboardButton::make('نسخه پلاس چیه؟', url: 'https://t.me/kermpluslearn/18', style: 'danger', icon: '5433758796289685818')
            )
            ->addRow(InlineKeyboardButton::make('تفاوت نسخه پرو و پلاس', url: 'https://t.me/kermpluslearn/17', style: 'danger', icon: '6226546610227121867'));

        if ($backCallback) {
            $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: $backCallback, style: 'danger', icon: '5352759161945867747'));
        }

        return $keyboard;
    }
}
