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
                InlineKeyboardButton::make('اس ام اس بمبر رایگان', callback_data: 'bomber_free_sms', style: 'danger', icon: '5841267724285646096')
            )
            ->addRow(
                InlineKeyboardButton::make('اس ام اس بمبر پلاس', callback_data: 'bomber_plus_sms', style: 'danger', icon: '5469913852462242978'),
                InlineKeyboardButton::make('کال بمبر پلاس', callback_data: 'bomber_plus_call', style: 'danger', icon: '5172893417717367746')
            )
            ->addRow(
                InlineKeyboardButton::make('بمبر ترکیبی پلاس', callback_data: 'bomber_combo_plus', style: 'danger', icon: '5269535069550162819')
            )
            ->addRow(
                InlineKeyboardButton::make('ایمیل بمبر پلاس', callback_data: 'user_emailbomb', style: 'danger', icon: '5456174900622412791')
            )
            ->addRow(
                InlineKeyboardButton::make('آموزش استفاده از بمبر', url: 'https://t.me/kermpluslearn/6', style: 'danger', icon: '5470060791883374114')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon: '5352759161945867747')
            );
    }
}
