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
                InlineKeyboardButton::make('💣 بمبر 💣', callback_data: 'bomber_menu'),
                InlineKeyboardButton::make('📝 ریپورتر 📝', callback_data: 'reporter_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('😈 مزاحم ساز 😈', callback_data: 'user_autofiller')
            )
            ->addRow(
                InlineKeyboardButton::make('📵 ریستر موبایل 📵', callback_data: 'not_implemented'),
                InlineKeyboardButton::make('🌪️ طوفان تبلیغات 🌪️', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('🔋 خرابکاری باتری 🔋', callback_data: 'not_implemented'),
                InlineKeyboardButton::make('🔝 حافظه پر کن 🔝', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('📱 کرم ریزی رو موبایل 📱', callback_data: 'mobile_kerm_menu')
            )
            ->addRow(
                InlineKeyboardButton::make('👻 ری اکشنر منفی 👻', callback_data: 'channel_reaction')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu')
            );
    }
}
