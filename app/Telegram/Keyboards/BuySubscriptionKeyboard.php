<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BuySubscriptionKeyboard
{
    /**
     * @param iterable<int,\App\Models\SubscriptionPlan> $plans
     */
    public static function make(iterable $plans): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($plans as $plan) {
            $keyboard->addRow(InlineKeyboardButton::make("✅ {$plan->name}", callback_data: "select_plan_{$plan->id}", style: 'danger'));
        }

        return $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'user_profile', style: 'danger', icon: '5352759161945867747'));
    }
}
