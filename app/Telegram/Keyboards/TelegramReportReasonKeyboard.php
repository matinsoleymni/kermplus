<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class TelegramReportReasonKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('کودک آزاری', callback_data: 'telegram_reason_child_abuse')
            )
            ->addRow(
                InlineKeyboardButton::make('خشونت', callback_data: 'telegram_reason_violence')
            )
            ->addRow(
                InlineKeyboardButton::make('کالا و خدمات غیرقانونی', callback_data: 'telegram_reason_illegal_goods')
            )
            ->addRow(
                InlineKeyboardButton::make('محتوای بزرگسالان غیرقانونی', callback_data: 'telegram_reason_illegal_adult')
            )
            ->addRow(
                InlineKeyboardButton::make('داده ‌های شخصی', callback_data: 'telegram_reason_personal_data')
            )
            ->addRow(
                InlineKeyboardButton::make('کلاهبرداری', callback_data: 'telegram_reason_fraud')
            )
            ->addRow(
                InlineKeyboardButton::make('کپی رایت', callback_data: 'telegram_reason_copyright')
            )
            ->addRow(
                InlineKeyboardButton::make('اسپم', callback_data: 'telegram_reason_spam')
            )
            ->addRow(
                InlineKeyboardButton::make('غیرقانونی نیست ، اما باید حذف شود', callback_data: 'telegram_reason_other')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'reporter_menu')
            );
    }
}
