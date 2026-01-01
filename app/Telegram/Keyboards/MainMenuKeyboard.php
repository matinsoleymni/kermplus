<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MainMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('👤 حساب من 👤', callback_data: 'user_profile')
            )
            ->addRow(
                InlineKeyboardButton::make('🪱 کرم ریزی 🪱', callback_data: 'kerm_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('📞 پشتیبانی 📞', url: 'https://t.me/kermsup')
            )
            ->addRow(
                InlineKeyboardButton::make('🤍 لیست سفید 🤍', callback_data: 'whitelist_add')
            )
            ->addRow(
                InlineKeyboardButton::make('🎗 ارتقا به نسخه پلاس', callback_data: 'buy_subscription')
            );
    }
}
