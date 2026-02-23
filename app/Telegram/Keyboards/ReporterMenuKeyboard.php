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
                InlineKeyboardButton::make('ریپورتر تلگرام', callback_data: 'reporter_telegram_menu', style: 'danger', icon: '5330237710655306682')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورتر روبیکا', callback_data: 'reporter_rubika_menu', style: 'danger', icon: '4978973209056511046')
            )
            ->addRow(
                InlineKeyboardButton::make('ریپورتر اینستاگرام', callback_data: 'reporter_instagram_menu', style: 'danger', icon: '5319160079465857105')
            )
            ->addRow(
                InlineKeyboardButton::make('آموزش استفاده از ریپورتر', url: 'https://t.me/kermpluslearn/7', style: 'danger', icon: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon: '5352759161945867747')
            );
    }
}
