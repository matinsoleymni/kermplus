<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MainMenuKeyboard
{
    public static function make(bool $hasActiveSubscription = false): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('حساب من', callback_data: 'user_profile', style: 'danger', icon:'4904848288345228262')
            )
            ->addRow(
                InlineKeyboardButton::make('کرم ریزی', callback_data: 'kerm_menu', style: 'danger', icon: '5134654202894615343')
            )
            ->addRow(
                InlineKeyboardButton::make('پشتیبانی', url: 'https://t.me/kermsup', style: 'danger', icon: '5172893417717367746')
            )
            ->addRow(
                InlineKeyboardButton::make('لیست سفید', callback_data: 'whitelist_add', style: 'danger', icon: '5429392313493242588')
            );

        if (!$hasActiveSubscription) {
            $keyboard->addRow(
                InlineKeyboardButton::make('ارتقا به نسخه پلاس', callback_data: 'buy_subscription', style: 'danger', icon: '4929619512224909015')
            );
        }

        return $keyboard;
    }
}
