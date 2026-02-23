<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

class SubscriptionActivationNotificationService
{
    public function notifyIfEligible(User $user, SubscriptionPlan $plan): bool
    {
        if (!$user->telegram_id) {
            return false;
        }

        $message = $this->messageForPlan($plan);
        if ($message === null) {
            return false;
        }

        try {
            app(Nutgram::class)->sendMessage($message, chat_id: (int) $user->telegram_id);
            return true;
        } catch (\Throwable $e) {
            logger()->warning('Failed to send subscription activation message.', [
                'user_id' => $user->id,
                'telegram_id' => $user->telegram_id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function messageForPlan(SubscriptionPlan $plan): ?string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => "😍 مبارکه عزیزممم 🫶🫂\n\n“نسخه پرو💎” از ربات کرم پلاس برات فعال شد ✅\n\n• کانال آموزشی و توضیحات ربات : \n@kermpluslearn\n\n• کانال مخصوص بچه های نسخه پرو و پلاس :\nhttps://t.me/+ssw7ES-YS2NlNTFh",
            'plus' => "😍مبارکه عزیزممم 🫶🫂\n\n“نسخه پلاس👑” از ربات کرم پلاس برات فعال شد ✅\n\n• کانال آموزشی و توضیحات ربات : \n@kermpluslearn\n\n• کانال مخصوص بچه های نسخه پرو و پلاس :\nhttps://t.me/+ssw7ES-YS2NlNTFh",
            default => null,
        };
    }
}
