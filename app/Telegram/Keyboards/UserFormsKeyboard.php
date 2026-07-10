<?php

namespace App\Telegram\Keyboards;

use Illuminate\Support\Collection;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class UserFormsKeyboard
{
    /**
     * @param iterable<int,\App\Models\Form>|Collection $forms
     */
    public static function make(iterable $forms): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($forms as $form) {
            $keyboard->addRow(InlineKeyboardButton::make("{$form->name}", callback_data: "fill_form_{$form->id}", style: 'danger', icon_custom_emoji_id: '5470060791883374114'));
        }

        return $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'main_menu', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));
    }
}
