<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartKeyboard
{
    public static function make(bool $hasActiveSubscription = false): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('👤 حساب من 👤', callback_data: 'user_profile', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('🪱 کرم ریزی 🪱', callback_data: 'kerm_menu', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('📞 پشتیبانی 📞', url: 'https://t.me/kermsup', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('🤍 لیست سفید 🤍', callback_data: 'whitelist_menu', style: 'danger')
            );

        if (!$hasActiveSubscription) {
            $keyboard->addRow(
                InlineKeyboardButton::make('ارتقا به نسخه پلاس', callback_data: 'buy_subscription', style: 'danger')
            );
        }

        return $keyboard;
    }
}
