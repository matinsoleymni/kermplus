<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use SergiX44\Nutgram\Nutgram;

class PaymentPreCheckoutHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $query = $bot->preCheckoutQuery();
        if (!$query) {
            return;
        }

        $payload = $query->invoice_payload ?? '';
        if (!str_starts_with($payload, 'STAR-SUB-')) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'پرداخت پشتیبانی نمی‌شود.');
            return;
        }

        $localUser = User::where('telegram_id', $query->from->id)->first();
        if (!$localUser) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'کاربر یافت نشد. لطفا به @kermsup پیام بدید.');
            return;
        }

        $planId = null;
        $payloadUserId = null;
        if (preg_match('/STAR-SUB-(\\d+)-.+-U(\\d+)/', $payload, $m)) {
            $planId = (int) $m[1];
            $payloadUserId = (int) $m[2];
        }

        if (!$planId) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'پرداخت نامعتبر است. لطفا مجددا تلاش کنید.');
            return;
        }

        if ($payloadUserId && $payloadUserId !== $localUser->id) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'پرداخت با کاربر فعلی همخوانی ندارد.');
            return;
        }

        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'پلن پیدا نشد. لطفا مجددا تلاش کنید.');
            return;
        }

        $expectedStars = $plan->starsPrice();
        if ($query->total_amount !== $expectedStars || ($query->currency ?? '') !== 'XTR') {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'مبلغ پرداخت نامعتبر است.');
            return;
        }

        try {
            $payment = SubscriptionPayment::firstOrNew([
                'provider' => 'telegram_star',
                'invoice_id' => $payload,
            ]);

            $meta = (array) ($payment->meta ?? []);
            $meta['type'] = 'telegram_star';
            $meta['pre_checkout_query_id'] = $query->id;

            $payment->fill([
                'user_id' => $localUser->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'pre_checkout',
                'price_amount' => $expectedStars,
                'price_currency' => 'xtr',
                'pay_amount' => $query->total_amount,
                'pay_currency' => $query->currency ?? 'XTR',
                'meta' => $meta,
            ]);

            $payment->save();
        } catch (\Throwable) {
            $bot->answerPreCheckoutQuery(ok: false, error_message: 'خطا در تایید پرداخت. لطفا بعدا تلاش کنید.');
            return;
        }

        $bot->answerPreCheckoutQuery(ok: true, pre_checkout_query_id: $query->id);
    }
}
