<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PlusRequiredKeyboard
{
    public static function make(?string $backCallback = null): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🩸 نسخه پلاس چیه؟🩸', callback_data: 'plus_info'))
            ->addRow(
                InlineKeyboardButton::make('🎗 ارتقا به نسخه پلاس🎗', callback_data: 'buy_subscription'),
                InlineKeyboardButton::make('📞 پشتیبانی 📞', url: 'https://t.me/kermsup')
            );

        if ($backCallback) {
            $keyboard->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: $backCallback));
        }

        return $keyboard;
    }
}
