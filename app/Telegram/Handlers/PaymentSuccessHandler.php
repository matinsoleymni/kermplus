<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
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
            $bot->sendMessage('❌ کاربر یافت نشد. لطفا به @kermsup پیام بدید.');
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
            $bot->sendMessage('❌ پرداخت دریافت شد اما پلن مشخص نیست. لطفا به @kermsup پیام بدید');
            return;
        }

        // امنیت: payload باید با همان کاربر سازگار باشد
        if ($payloadUserId && $payloadUserId !== $localUser->id) {
            $bot->sendMessage('❌ عدم تطابق کاربر با پرداخت. لطفا به @kermsup پیام بدید');
            return;
        }

        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد. لطفا به @kermsup پیام بدید');
            return;
        }

        $payment = SubscriptionPayment::firstOrNew([
            'provider' => 'telegram_star',
            'invoice_id' => $payload,
        ]);

        if ($payment->exists && $payment->status === 'paid') {
            $bot->sendMessage('✅ پرداخت شما قبلاً ثبت شده است.');
            return;
        }

        $meta = (array) ($payment->meta ?? []);
        $meta['telegram_payment_charge_id'] = $success->telegram_payment_charge_id ?? null;
        $meta['provider_payment_charge_id'] = $success->provider_payment_charge_id ?? null;
        $meta['successful_payment_payload'] = $payload;

        $payment->fill([
            'user_id' => $localUser->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'paid',
            'price_amount' => $plan->starsPrice(),
            'price_currency' => 'xtr',
            'pay_amount' => $success->total_amount ?? null,
            'pay_currency' => $success->currency ?? 'XTR',
            'meta' => $meta,
        ]);
        $payment->save();

        // قبل از اعمال پلن جدید، همه اشتراک‌های فعال قبلی غیرفعال شوند.
        $localUser->subscriptions()
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->get()
            ->each(fn($sub) => $sub->cancel());

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->createSubscription($localUser, $plan, createdBy: null);

        $planName = mb_strtolower(trim((string) $plan->name));
        if (!in_array($planName, ['pro', 'plus'], true)) {
            $bot->sendMessage("✅ پرداخت با موفقیت انجام شد!\nپلن شما: {$plan->name}\nاز امروز می‌توانید از سرویس استفاده کنید.");
        }
    }
}
