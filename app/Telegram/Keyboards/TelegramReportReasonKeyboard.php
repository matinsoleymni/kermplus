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
                InlineKeyboardButton::make('کودک آزاری', callback_data: 'telegram_reason_child_abuse', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('خشونت', callback_data: 'telegram_reason_violence', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('کالا و خدمات غیرقانونی', callback_data: 'telegram_reason_illegal_goods', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('محتوای بزرگسالان غیرقانونی', callback_data: 'telegram_reason_illegal_adult', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('داده ‌های شخصی', callback_data: 'telegram_reason_personal_data', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('کلاهبرداری', callback_data: 'telegram_reason_fraud', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('کپی رایت', callback_data: 'telegram_reason_copyright', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('اسپم', callback_data: 'telegram_reason_spam', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('غیرقانونی نیست ، اما باید حذف شود', callback_data: 'telegram_reason_other', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_telegram_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
