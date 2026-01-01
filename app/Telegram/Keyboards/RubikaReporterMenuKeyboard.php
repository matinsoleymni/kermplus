<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RubikaReporterMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('👤 ریپورت اکانت 👤', callback_data: 'rubika_report_account')
            )
            ->addRow(
                InlineKeyboardButton::make('📢 ریپورت کانال 📢', callback_data: 'rubika_report_channel')
            )
            ->addRow(
                InlineKeyboardButton::make('👥 ریپورت گروه 👥', callback_data: 'rubika_report_group')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'reporter_menu')
            );
    }
}
