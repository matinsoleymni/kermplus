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
            $planName = mb_strtolower(trim((string) $plan->name));
            $label = match ($planName) {
                'pro' => 'ارتقا به نسخه پرو',
                'plus' => 'ارتقا به نسخه پلاس',
                default => "ارتقا به نسخه {$plan->name}",
            };
            $icon = match ($planName) {
                'pro' => '6244241334320762892',
                'plus' => '5433758796289685818',
                default => null,
            };

            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "select_plan_{$plan->id}", style: 'danger', icon: $icon));
        }

        return $keyboard->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'user_profile', style: 'danger', icon: '5352759161945867747'));
    }
}
