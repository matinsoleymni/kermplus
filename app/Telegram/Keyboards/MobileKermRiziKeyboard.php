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
                InlineKeyboardButton::make('فلشر', callback_data: 'not_implemented', style: 'danger', icon: '5866234163018861829'),
                InlineKeyboardButton::make('موزیکر', callback_data: 'not_implemented', style: 'danger', icon: '5222472119295684375')
            )
            ->addRow(
                InlineKeyboardButton::make('آفر', callback_data: 'not_implemented', style: 'danger', icon: '5258077595748030166'),
                InlineKeyboardButton::make('🗑️ دیلیتر', callback_data: 'not_implemented', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('آموزش استفاده از کرم ریزی رو موبایل', url: 'https://t.me/kermpluslearn/14', style: 'danger', icon: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon: '5352759161945867747')
            );
    }
}
