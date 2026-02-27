<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

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

        $chatId = (int) $user->telegram_id;
        $bot = app(Nutgram::class);

        try {
            $photo = $this->photoForPlan($plan);
            if ($photo !== null) {
                try {
                    $bot->sendPhoto(
                        photo: $photo,
                        caption: $message,
                        chat_id: $chatId,
                        parse_mode: 'HTML',
                    );

                    return true;
                } catch (\Throwable $e) {
                    logger()->warning('Failed to send subscription activation photo, falling back to text message.', [
                        'user_id' => $user->id,
                        'telegram_id' => $user->telegram_id,
                        'plan_id' => $plan->id,
                        'plan_name' => $plan->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $bot->sendMessage($message, chat_id: $chatId, parse_mode: 'HTML');
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
            'pro' => "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> مبارکه عزیزممم 🫶🫂\n\n\"نسخه پرو<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>\" از ربات کرم پلاس برات فعال شد ✅\n\n• کانال آموزشی و توضیحات ربات:\n@kermpluslearn\n\n• کانال مخصوص بچه های نسخه پرو و پلاس:\nhttps://t.me/+ssw7ES-YS2NlNTFh",
            'plus' => "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> مبارکه عزیزممم 🫶🫂\n\n\"نسخه پلاس<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>\" از ربات کرم پلاس برات فعال شد ✅\n\n• کانال آموزشی و توضیحات ربات:\n@kermpluslearn\n\n• کانال مخصوص بچه های نسخه پرو و پلاس:\nhttps://t.me/+ssw7ES-YS2NlNTFh",
            default => null,
        };
    }

    private function photoForPlan(SubscriptionPlan $plan): ?InputFile
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        $fileName = match ($planName) {
            'pro' => 'buy-pro.png',
            'plus' => 'buy-plus.png',
            default => null,
        };

        if ($fileName === null) {
            return null;
        }

        $path = public_path('images/' . $fileName);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return null;
        }

        return InputFile::make($path, $fileName);
    }
}
