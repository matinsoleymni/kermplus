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
                InlineKeyboardButton::make('کرم ریزی اینستاگرام', callback_data: 'app_action:INSTAGRAM', style: 'danger', icon_custom_emoji_id: '5866234163018861829'),
                InlineKeyboardButton::make('موزیکر', callback_data: 'app_action:MUSIC', style: 'danger', icon_custom_emoji_id: '5222472119295684375')
            )
            ->addRow(
                InlineKeyboardButton::make('آفر', callback_data: 'app_action:LOCK', style: 'danger', icon_custom_emoji_id: '5258077595748030166'),
                InlineKeyboardButton::make('دیلیتر', callback_data: 'app_action:REMOVE_FILE', style: 'danger', icon_custom_emoji_id: '5879896690210639947')
            )
            ->addRow(
                InlineKeyboardButton::make('لگ انداختن', callback_data: 'app_action:LLL', style: 'danger', icon_custom_emoji_id: '5465665476971471368'),
                InlineKeyboardButton::make('طوفان تبلیغات 🌪', callback_data: 'app_action:OVERLAY', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('خرابکاری باتری', callback_data: 'app_action:LINK', style: 'danger', icon_custom_emoji_id: '4904626998745237074'),
                InlineKeyboardButton::make('حافظه پر کن', callback_data: 'app_action:FILE', style: 'danger', icon_custom_emoji_id: '4904832912362309275')
            )
            ->addRow(
                InlineKeyboardButton::make('دریافت چت تلگرام و اینستاگرام', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('آموزش استفاده از کرم ریزی رو موبایل', url: 'https://t.me/kermpluslearn/14', style: 'danger', icon_custom_emoji_id: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
