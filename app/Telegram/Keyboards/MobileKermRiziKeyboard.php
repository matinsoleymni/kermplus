<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MobileKermRiziKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📸 فلشر 📸', callback_data: 'not_implemented'),
                InlineKeyboardButton::make('🎶 موزیکر 🎶', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('📴 آفر 📴', callback_data: 'not_implemented'),
                InlineKeyboardButton::make('🗑️ دیلیتر 🗑️', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('✍️ آموزش استفاده از کرم ریزی رو موبایل ✍️', url: 'https://t.me/kermpluslearn/14')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'kerm_menu')
            );
    }
}
