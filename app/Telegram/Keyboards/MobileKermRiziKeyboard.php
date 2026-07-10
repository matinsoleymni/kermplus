<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MobileKermRiziKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            // ->addRow(
            //     InlineKeyboardButton::make('فلشر', callback_data: 'kerm_action:flasher', style: 'danger', icon_custom_emoji_id: '5866234163018861829'),
            //     InlineKeyboardButton::make('موزیکر', callback_data: 'kerm_action:music', style: 'danger', icon_custom_emoji_id: '5222472119295684375')
            // )
            // ->addRow(
            //     InlineKeyboardButton::make('آفر', callback_data: 'kerm_action:screen_off', style: 'danger', icon_custom_emoji_id: '5258077595748030166'),
            //     InlineKeyboardButton::make('🗑️ دیلیتر', callback_data: 'kerm_action:deleter', style: 'danger')
            // )
            // ->addRow(
            //     InlineKeyboardButton::make('آموزش استفاده از کرم ریزی رو موبایل', url: 'https://t.me/kermpluslearn/14', style: 'danger', icon_custom_emoji_id: '5470060791883374114')
            // )
            // ->addRow(
            //     InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            // );

            ->addRow(
                InlineKeyboardButton::make('فلشر', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5866234163018861829'),
                InlineKeyboardButton::make('موزیکر', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5222472119295684375')
            )
            ->addRow(
                InlineKeyboardButton::make('آفر', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5258077595748030166'),
                InlineKeyboardButton::make('🗑️ دیلیتر', callback_data: 'check_countdown', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('آموزش استفاده از کرم ریزی رو موبایل', url: 'https://t.me/kermpluslearn/14', style: 'danger', icon_custom_emoji_id: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
