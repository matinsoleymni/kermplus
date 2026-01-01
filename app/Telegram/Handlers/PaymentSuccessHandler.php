<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use SergiX44\Nutgram\Nutgram;

class PaymentSuccessHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        $success = $message?->successful_payment;
        $tgUser = $message?->from;
        if (!$success || !$tgUser) {
            return;
        }

        $payload = $success->invoice_payload ?? '';
        if (!str_starts_with($payload, 'STAR-SUB-')) {
            // payments دیگر را فعلا نادیده بگیر
            return;
        }

        $localUser = User::where('telegram_id', $tgUser->id)->first();
        if (!$localUser) {
            $bot->sendMessage('❌ کاربر یافت نشد. لطفا با @kermsup تماس بگیرید.');
            return;
        }

        // payload نمونه: STAR-SUB-{planId}-{rand}-U{userId}
        $planId = null;
        $payloadUserId = null;
        if (preg_match('/STAR-SUB-(\\d+)-.+-U(\\d+)/', $payload, $m)) {
            $planId = (int) $m[1];
            $payloadUserId = (int) $m[2];
        }

        if (!$planId) {
            $bot->sendMessage('❌ پرداخت دریافت شد اما پلن مشخص نیست. لطفا با @kermsup تماس بگیرید.');
            return;
        }

        // امنیت: payload باید با همان کاربر سازگار باشد
        if ($payloadUserId && $payloadUserId !== $localUser->id) {
            $bot->sendMessage('❌ عدم تطابق کاربر با پرداخت. لطفا با @kermsup تماس بگیرید.');
            return;
        }

        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد. لطفا با @kermsup تماس بگیرید.');
            return;
        }

        // به‌روزرسانی رکورد پرداخت
        SubscriptionPayment::where('provider', 'telegram_star')
            ->where('invoice_id', $payload)
            ->update([
                'status' => 'paid',
                'pay_amount' => $success->total_amount ?? null,
                'pay_currency' => $success->currency ?? 'XTR',
                'meta' => [
                    'telegram_payment_charge_id' => $success->telegram_payment_charge_id ?? null,
                    'provider_payment_charge_id' => $success->provider_payment_charge_id ?? null,
                ],
            ]);

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->createSubscription($localUser, $plan, createdBy: null);

        $bot->sendMessage("✅ پرداخت با موفقیت انجام شد!\nپلن شما: {$plan->name}\nاز امروز می‌توانید از سرویس استفاده کنید.");
    }
}
