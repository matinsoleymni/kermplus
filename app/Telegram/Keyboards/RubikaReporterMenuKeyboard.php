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
                InlineKeyboardButton::make('ریپورت اکانت', callback_data: 'rubika_report_account', style: 'danger', icon_custom_emoji_id: '4904848288345228262')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورت کانال', callback_data: 'rubika_report_channel', style: 'danger', icon_custom_emoji_id: '4918203446202467778')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورت گروه', callback_data: 'rubika_report_group', style: 'danger', icon_custom_emoji_id: '6226448624843231576')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
