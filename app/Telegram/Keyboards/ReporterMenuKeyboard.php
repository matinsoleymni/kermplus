<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReporterMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🟦 ریپورتر تلگرام 🟦', callback_data: 'reporter_telegram_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('🟧 ریپورتر روبیکا 🟧', callback_data: 'reporter_rubika_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('🟥 ریپورتر اینستاگرام 🟥', callback_data: 'reporter_instagram_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('✍️ آموزش استفاده از ریپورتر ✍️', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'kerm_menu')
            );
    }
}
