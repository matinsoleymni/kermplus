<?php

namespace App\Telegram\Handlers;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Payments\NowPaymentsService;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Payment\LabeledPrice;

class SelectPlanHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $tgUser = $bot->user();
        $local = $tgUser ? User::where('telegram_id', $tgUser->id)->first() : null;

        if (!$local) {
            $bot->sendMessage('❌ کاربر پیدا نشد.');
            return;
        }

        $data = $bot->callbackQuery()?->data ?? '';
        if ($bot->callbackQuery()) {
            // جلوگیری از پیام Timeout تلگرام
            $bot->answerCallbackQuery();
        }
        if (str_starts_with($data, 'select_plan_')) {
            $this->showPaymentMethods($bot, (int) str_replace('select_plan_', '', $data));
            return;
        }

        if (str_starts_with($data, 'pay_crypto_')) {
            $this->handleCryptoPayment($bot, $local, (int) str_replace('pay_crypto_', '', $data));
            return;
        }

        if (str_starts_with($data, 'pay_star_')) {
            $this->handleStarPayment($bot, $local, (int) str_replace('pay_star_', '', $data));
            return;
        }

        $bot->sendMessage('❌ درخواست نامعتبر.');
    }

    private function showPaymentMethods(Nutgram $bot, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $stars = $plan->starsPrice();
        $usd = number_format($plan->usdPrice(), 2);
        $irr = number_format($plan->irrPrice(), 0);
        $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';

        $msg = "🧾 **پلن انتخاب‌شده:** {$plan->name}\n";
        $msg .= "💰 مبلغ: {$usd}$ | {$irr} ریال | {$stars} استار\n";
        $msg .= "📅 مدت: {$durationText}\n";
        $msg .= "💬 SMS روزانه: {$plan->max_sms_per_day}\n";
        $msg .= "📧 Email روزانه: {$plan->max_email_per_day}\n\n";
        $msg .= "روش پرداخت را انتخاب کنید:";

        $kb = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('💳 پرداخت رمز ارزی', callback_data: "pay_crypto_{$plan->id}", style: 'danger'),
                InlineKeyboardButton::make('⭐️ پرداخت با استار تلگرام', callback_data: "pay_star_{$plan->id}", style: 'danger')
            )
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $kb, parse_mode: 'Markdown');
    }

    private function handleCryptoPayment(Nutgram $bot, User $user, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $stars = $plan->starsPrice();
        $usdAmount = $plan->usdPrice();
        $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';
        $orderId = 'SUB-' . $plan->id . '-' . Str::upper(Str::random(6)) . '-U' . $user->id;

        try {
            /** @var NowPaymentsService $payments */
            $payments = app(NowPaymentsService::class);
            $payment = $payments->createPayment(
                $usdAmount,
                $orderId,
                "اشتراک {$plan->name}",
                $user->email
            );
        } catch (\Throwable $e) {
            $bot->sendMessage('❌ خطا در ساخت فاکتور NOWPayments: ' . $e->getMessage());
            return;
        }

        SubscriptionPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'provider' => 'nowpayments',
            'invoice_id' => $payment['purchase_id'] ?? null,
            'invoice_url' => null,
            'payment_id' => $payment['payment_id'] ?? null,
            'status' => $payment['payment_status'] ?? 'pending',
            'price_amount' => $payment['price_amount'] ?? $usdAmount,
            'price_currency' => $payment['price_currency'] ?? config('payments.nowpayments.price_currency', 'usd'),
            'pay_amount' => $payment['pay_amount'] ?? null,
            'pay_currency' => $payment['pay_currency'] ?? config('payments.nowpayments.pay_currency'),
            'meta' => $payment,
        ]);

        $msg = "🧾 **فاکتور پرداخت ایجاد شد!**\n\n";
        $msg .= "📋 پلن: {$plan->name}\n";
        $msg .= "💰 مبلغ: " . number_format($usdAmount, 2) . "$ (~{$stars} استار)\n";
        $msg .= "📅 مدت: {$durationText}\n";
        $msg .= "💬 SMS روزانه: {$plan->max_sms_per_day}\n";
        $msg .= "📧 Email روزانه: {$plan->max_email_per_day}\n\n";
        $msg .= "پرداخت کریپتویی شما با NOWPayments آماده است. مبلغ زیر را به آدرس درج‌شده ارسال کنید:\n\n";
        $payAmount = $payment['pay_amount'] ?? '---';
        $payCurrency = strtoupper($payment['pay_currency'] ?? config('payments.nowpayments.pay_currency', '---'));
        $msg .= "💸 مبلغ: {$payAmount} {$payCurrency}\n";
        $msg .= "🔗 آدرس: " . ($payment['pay_address'] ?? '---') . "\n";
        if (!empty($payment['payin_extra_id'])) {
            $msg .= "🧾 Memo/Tag: {$payment['payin_extra_id']}\n";
        }
        if (!empty($payment['network'])) {
            $msg .= "🌐 شبکه: {$payment['network']}\n";
        }
        $msg .= "\nپس از پرداخت، رسید را برای پشتیبانی ارسال کنید.";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'Markdown');
    }

    private function handleStarPayment(Nutgram $bot, User $user, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $stars = $plan->starsPrice();
        $usd = number_format($plan->usdPrice(), 2);

        $payload = 'STAR-SUB-' . $plan->id . '-' . Str::upper(Str::random(6)) . '-U' . $user->id;
        $price = new LabeledPrice(label: $plan->name, amount: $stars);

        $bot->sendMessage("⭐️ پرداخت استار برای پلن {$plan->name}\nمبلغ: {$usd} دلار (~{$stars} استار)\nدکمه Pay را بزنید.");

        try {
            $bot->sendInvoice(
                title: "اشتراک {$plan->name}",
                description: "پرداخت با استار تلگرام برای پلن {$plan->name} ({$plan->duration_days} روزه)",
                payload: $payload,
                provider_token: '', // استار تلگرام
                currency: 'XTR',
                prices: [$price],
            );
        } catch (\Throwable $e) {
            $bot->sendMessage('❌ خطا در ارسال فاکتور استار: ' . $e->getMessage());
            return;
        }

        SubscriptionPayment::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'provider' => 'telegram_star',
            'invoice_id' => $payload,
            'status' => 'pending',
            'price_amount' => $stars,
            'price_currency' => 'xtr',
            'pay_amount' => $stars,
            'pay_currency' => 'XTR',
            'meta' => [
                'type' => 'telegram_star',
                'usd_price' => $plan->usdPrice(),
                'irr_price' => $plan->irrPrice(),
            ],
        ]);
    }
}
