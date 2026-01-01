<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class InstagramReporterMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('👤 ریپورت پیج 👤', callback_data: 'instagram_report_page')
            )
            ->addRow(
                InlineKeyboardButton::make('📮 ریپورت پست 📮', callback_data: 'instagram_report_post')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'reporter_menu')
            );
    }
}
