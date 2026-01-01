<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class InstagramReportReasonKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('اسپم', callback_data: 'instagram_reason_spam')
            )
            ->addRow(
                InlineKeyboardButton::make('مزاحمت و آزار', callback_data: 'instagram_reason_harassment')
            )
            ->addRow(
                InlineKeyboardButton::make('خشونت', callback_data: 'instagram_reason_violence')
            )
            ->addRow(
                InlineKeyboardButton::make('فروش یا تبلیغ کالای غیر مجاز', callback_data: 'instagram_reason_illegal_sales')
            )
            ->addRow(
                InlineKeyboardButton::make('برهنگی یا فعالیت جنسی', callback_data: 'instagram_reason_nudity')
            )
            ->addRow(
                InlineKeyboardButton::make('کلاهبرداری', callback_data: 'instagram_reason_fraud')
            )
            ->addRow(
                InlineKeyboardButton::make('اطلاعات غلط', callback_data: 'instagram_reason_misinformation')
            )
            ->addRow(
                InlineKeyboardButton::make('ازش خوشم نمیاد', callback_data: 'instagram_reason_dislike')
            )
            ->addRow(
                InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'reporter_menu')
            );
    }
}
