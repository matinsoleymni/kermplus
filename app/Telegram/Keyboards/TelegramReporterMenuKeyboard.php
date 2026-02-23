<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TelegramReporterMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('ریپورت اکانت', callback_data: 'telegram_report_account', style: 'danger', icon: '4904848288345228262')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورت کانال', callback_data: 'telegram_report_channel', style: 'danger', icon: '4918203446202467778')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورت پست', callback_data: 'telegram_report_post', style: 'danger', icon: '6226448624843231576')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_menu', style: 'danger', icon: '5352759161945867747')
            );
    }
}
