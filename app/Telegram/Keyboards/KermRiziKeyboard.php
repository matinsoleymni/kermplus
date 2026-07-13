<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class KermRiziKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('بمبر', callback_data: 'bomber_menu', style: 'danger', icon_custom_emoji_id: '5134377151734219769'),
                InlineKeyboardButton::make('ریپورتر', callback_data: 'reporter_menu', style: 'danger', icon_custom_emoji_id: '5334882760735598374')
            )
            ->addRow(
                InlineKeyboardButton::make('مزاحم ساز', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5354971413700680895')
            )
            ->addRow(
                InlineKeyboardButton::make('📵 ریستر موبایل 📵', callback_data: 'check_countdown', style: 'danger'),
                InlineKeyboardButton::make('🌪️ طوفان تبلیغات 🌪️', callback_data: 'check_countdown', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('خرابکاری باتری', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '4904626998745237074'),
                InlineKeyboardButton::make('حافظه پر کن', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '4904832912362309275')
            )
            ->addRow(
                InlineKeyboardButton::make('کرم ریزی رو موبایل', callback_data: 'mobile_kerm_menu', style: 'danger', icon_custom_emoji_id: '5407025283456835913')
            )
            ->addRow(
                InlineKeyboardButton::make('ری اکشنر منفی', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5305388752162539722')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
