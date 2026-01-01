<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class UserProfileKeyboard
{
    public static function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💳 خرید اشتراک', callback_data: 'buy_subscription'), InlineKeyboardButton::make('🎁 دعوت دوستان', callback_data: 'user_referral'))
            ->addRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'main_menu'));
    }
}
