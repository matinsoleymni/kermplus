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
        if (preg_match('/^select_plan_(\d+)$/', $data, $m)) {
            $this->showPaymentMethods($bot, (int) $m[1]);
            return;
        }

        if (preg_match('/^pay_crypto_(trx|ton)_(\d+)$/', $data, $m)) {
            $this->handleCryptoPayment($bot, $local, (int) $m[2], $m[1]);
            return;
        }

        if (preg_match('/^pay_crypto_(\d+)$/', $data, $m)) {
            $this->showCryptoPaymentCurrencies($bot, (int) $m[1]);
            return;
        }

        if (preg_match('/^pay_star_(\d+)$/', $data, $m)) {
            $this->handleStarPayment($bot, $local, (int) $m[1]);
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

        $msg = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> پلن انتخاب‌شده: <b>{$plan->name}</b>\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> مبلغ: {$usd}$ | {$irr} ریال | {$stars} استار\n";
        $msg .= "📅 مدت: {$durationText}\n";
        $msg .= "💬 SMS روزانه: {$plan->max_sms_per_day}\n";
        $msg .= "📧 Email روزانه: {$plan->max_email_per_day}\n\n";
        $msg .= "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> روش پرداخت را انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>";

        $kb = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('ترون ( TRX )', callback_data: "pay_crypto_trx_{$plan->id}", style: 'danger', icon: '5391239186994967770'))
            ->addRow(InlineKeyboardButton::make('تون ( TON )', callback_data: "pay_crypto_ton_{$plan->id}", style: 'danger', icon: '5265151230790884988'))
            ->addRow(InlineKeyboardButton::make('پرداخت با استار تلگرام', callback_data: "pay_star_{$plan->id}", style: 'danger', icon: '5958376256788502078'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $kb, parse_mode: 'HTML');
    }

    private function showCryptoPaymentCurrencies(Nutgram $bot, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $usd = number_format($plan->usdPrice(), 2, '.', '');
        $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';

        $msg = $this->planSectionTitle($plan) . "\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> مبلغ: {$usd}$\n";
        $msg .= "📅 مدت: {$durationText}\n\n";
        $msg .= "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> ارز پرداخت کریپتویی را انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('ترون ( TRX )', callback_data: "pay_crypto_trx_{$plan->id}", style: 'danger', icon: '5391239186994967770'))
            ->addRow(InlineKeyboardButton::make('تون ( TON )', callback_data: "pay_crypto_ton_{$plan->id}", style: 'danger', icon: '5265151230790884988'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_sub_crypto', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function handleCryptoPayment(Nutgram $bot, User $user, int $planId, string $asset): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $payCurrency = mb_strtolower(trim($asset));
        if (!in_array($payCurrency, ['trx', 'ton'], true)) {
            $bot->sendMessage('❌ ارز کریپتویی نامعتبر است.');
            return;
        }

        $assetLabel = mb_strtoupper($payCurrency);
        $usdAmount = $plan->usdPrice();
        $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';
        $orderId = 'SUB-' . $plan->id . '-' . $assetLabel . '-' . Str::upper(Str::random(6)) . '-U' . $user->id;

        try {
            /** @var NowPaymentsService $payments */
            $payments = app(NowPaymentsService::class);
            $payment = $payments->createPayment(
                $usdAmount,
                $orderId,
                "اشتراک {$plan->name}",
                $user->email,
                $payCurrency
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

        $msg = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> <b>فاکتور پرداخت ایجاد شد!</b>\n\n";
        $msg .= "📋 پلن: {$plan->name}\n";
        $msg .= "🌐 ارز انتخابی: {$assetLabel}\n";
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
            ->addRow(InlineKeyboardButton::make('برسی خرید', url: 'https://t.me/kermsup', style: 'danger', icon: '6296367896398399651'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function handleStarPayment(Nutgram $bot, User $user, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        $stars = $plan->starsPrice();

        $payload = 'STAR-SUB-' . $plan->id . '-' . Str::upper(Str::random(6)) . '-U' . $user->id;
        $price = new LabeledPrice(label: $plan->name, amount: $stars);
        $invoiceDescription = "🪱 جهت پرداخت هزینه استارزی برای ارتقای ربات کرم پلاس به نسخه پلاس به صورت دائمی با استارز ⭐️ ، لطفا روی دکمه زیر کلیک کنید 👇";

        try {
            $bot->sendInvoice(
                title: "خرید اشتراک",
                description: $invoiceDescription,
                payload: $payload,
                provider_token: '',
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

    private function planSectionTitle(SubscriptionPlan $plan): string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => "<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji> <b>اشتراک پرو</b> <tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>",
            'plus' => "<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji> <b>اشتراک پلاس</b> <tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>",
            default => "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> پلن انتخاب‌شده: <b>{$plan->name}</b>",
        };
    }
}
