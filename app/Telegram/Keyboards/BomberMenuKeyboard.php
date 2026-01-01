<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BomberMenuKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🆓 اس ام اس بمبر رایگان 🆓', callback_data: 'bomber_free_sms')
            )
            ->addRow(
                InlineKeyboardButton::make('🧨 اس ام اس بمبر پلاس 🧨', callback_data: 'bomber_plus_sms'),
                InlineKeyboardButton::make('📞 کال بمبر پلاس 📞', callback_data: 'bomber_plus_call')
            )
            ->addRow(
                InlineKeyboardButton::make('🩸 بمبر ترکیبی پلاس 🩸', callback_data: 'bomber_combo_plus')
            )
            ->addRow(
                InlineKeyboardButton::make('📧 ایمیل بمبر پلاس 📧', callback_data: 'user_emailbomb')
            )
            ->addRow(
                InlineKeyboardButton::make('✍️ آموزش استفاده از بمبر ✍️', callback_data: 'not_implemented')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'kerm_menu')
            );
    }
}
