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
                InlineKeyboardButton::make('اسپم', callback_data: 'instagram_reason_spam', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('مزاحمت و آزار', callback_data: 'instagram_reason_harassment', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('خشونت', callback_data: 'instagram_reason_violence', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('فروش یا تبلیغ کالای غیر مجاز', callback_data: 'instagram_reason_illegal_sales', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('برهنگی یا فعالیت جنسی', callback_data: 'instagram_reason_nudity', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('کلاهبرداری', callback_data: 'instagram_reason_fraud', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('اطلاعات غلط', callback_data: 'instagram_reason_misinformation', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('ازش خوشم نمیاد', callback_data: 'instagram_reason_dislike', style: 'danger')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'reporter_instagram_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
