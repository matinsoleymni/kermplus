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
                InlineKeyboardButton::make('اس ام اس بمبر رایگان', callback_data: 'not_implemented', style: 'danger', icon_custom_emoji_id: '5841267724285646096')
            )
            ->addRow(
                InlineKeyboardButton::make('اس ام اس بمبر پلاس', callback_data: 'not_implemented', style: 'danger', icon_custom_emoji_id: '5469913852462242978'),
                InlineKeyboardButton::make('کال بمبر پلاس', callback_data: 'not_implemented', style: 'danger', icon_custom_emoji_id: '5172893417717367746')
            )
            ->addRow(
                InlineKeyboardButton::make('بمبر ترکیبی پلاس', callback_data: 'not_implemented', style: 'danger', icon_custom_emoji_id: '5269535069550162819')
            )
            ->addRow(
                InlineKeyboardButton::make('ایمیل بمبر پلاس', callback_data: 'check_countdown', style: 'danger', icon_custom_emoji_id: '5456174900622412791')
            )
            ->addRow(
                InlineKeyboardButton::make('بمبر چیه؟', url: 'https://t.me/kermpluslearn/6', style: 'danger', icon_custom_emoji_id: '5134377151734219769')
            )
            ->addRow(
                InlineKeyboardButton::make('بازگشت', callback_data: 'kerm_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747')
            );
    }
}
