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
                InlineKeyboardButton::make('ریپورت پیج', callback_data: 'instagram_report_page', style: 'danger', icon: '4904848288345228262')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورت پست', callback_data: 'instagram_report_post', style: 'danger', icon: '6226448624843231576')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_menu', style: 'danger', icon: '5352759161945867747')
            );
    }
}
