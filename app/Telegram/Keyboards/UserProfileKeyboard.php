<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class UserProfileKeyboard
{
    public static function make(bool $hasActiveSubscription = false): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if (!$hasActiveSubscription) {
            $keyboard->addRow(
                InlineKeyboardButton::make('خرید اشتراک', callback_data: 'buy_subscription', style: 'danger'),
                InlineKeyboardButton::make('دعوت دوستان', callback_data: 'user_referral', style: 'danger', icon_custom_emoji_id: '4913497231492908158')
            );
        } else {
            $keyboard->addRow(
                InlineKeyboardButton::make('دعوت دوستان', callback_data: 'user_referral', style: 'danger', icon_custom_emoji_id: '4913497231492908158')
            );
        }

        return $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
    }
}
