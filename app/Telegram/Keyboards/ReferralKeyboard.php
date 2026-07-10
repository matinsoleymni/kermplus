<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ReferralKeyboard
{
    public static function make(bool $canClaimReward = false): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($canClaimReward) {
            $keyboard->addRow(
                InlineKeyboardButton::make('دریافت اشتراک هدیه', callback_data: 'referral_claim', style: 'danger', icon_custom_emoji_id: '5361649430715972984')
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('بروزرسانی آمار', callback_data: 'user_referral', style: 'danger', icon_custom_emoji_id: '6005843436479975944'),
            InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
        );

        return $keyboard;
    }
}
