<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReferralKeyboard
{
    public static function make(string $referralLink, string $shareText): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🔗 لینک من', url: $referralLink)
            );

        if (str_starts_with($referralLink, 'http')) {
            $shareUrl = 'https://t.me/share/url?url=' . urlencode($referralLink) . '&text=' . urlencode($shareText);
            $keyboard->addRow(
                InlineKeyboardButton::make('📨 ارسال برای دوستان', url: $shareUrl)
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('🔄 بروزرسانی آمار', callback_data: 'user_referral'),
            InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu')
        );

        return $keyboard;
    }
}
