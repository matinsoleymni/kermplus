<?php

namespace App\Telegram\Handlers;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PaymentGatewayService;
use App\Services\Payments\NowPaymentsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
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

        if ($bot->callbackQuery() && !str_starts_with($data, 'check_pay:') && !str_starts_with($data, 'check_gateway_pay:')) {
            $bot->answerCallbackQuery();
        }

        if (preg_match('/^select_plan_(\d+)$/', $data, $m)) {
            $this->showPaymentMethods($bot, (int) $m[1]);
            return;
        }

        if (preg_match('/^pay_rial_(\d+)$/', $data, $m)) {
            $this->handleRialPayment($bot, $local, (int) $m[1]);
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

        if (preg_match('/^check_pay:(\d+)$/', $data, $m)) {
            $this->handleCheckPayment($bot, $local, (int) $m[1]);
            return;
        }

        if (preg_match('/^check_gateway_pay:(\d+)$/', $data, $m)) {
            $this->handleCheckGatewayPayment($bot, $local, (int) $m[1]);
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

        $msg = "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> پلن انتخابشده: <b>{$plan->name}</b>\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> مبلغ: {$usd}$ | {$irr} تومان | {$stars} استار\n";
        $msg .= "📅 مدت: {$durationText}\n";
        $msg .= "<tg-emoji emoji-id=\"4927295007204836791\">🪱</tg-emoji> روش پرداخت را انتخاب کنید <tg-emoji emoji-id=\"5231102735817918643\">👇</tg-emoji>";

        $kb = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💳 پرداخت ریالی (کارت به کارت)', callback_data: "pay_rial_{$plan->id}"))
            ->addRow(InlineKeyboardButton::make('⭐ پرداخت با تلگرام استارز', callback_data: "pay_star_{$plan->id}"))
            ->addRow(InlineKeyboardButton::make('ترون ( TRX )', callback_data: "pay_crypto_trx_{$plan->id}", style: 'danger', icon_custom_emoji_id: '5391239186994967770'))
            ->addRow(InlineKeyboardButton::make('تون ( TON )', callback_data: "pay_crypto_ton_{$plan->id}", style: 'danger', icon_custom_emoji_id: '5265151230790884988'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $kb, parse_mode: 'HTML');
    }

    private function handleRialPayment(Nutgram $bot, User $user, int $planId): void
    {
        $plan = SubscriptionPlan::find($planId);
        if (!$plan) {
            $bot->sendMessage('❌ پلن پیدا نشد.');
            return;
        }

        try {
            /** @var SubscriptionService $subService */
            $subService = app(SubscriptionService::class);
            $payment = $subService->initiatePayment($user, $plan);
        } catch (\Throwable $e) {
            $bot->sendMessage('❌ خطا در ایجاد فاکتور درگاه: ' . $e->getMessage());
            return;
        }

        $planEmoji = $this->getPlanEmoji($plan->name);
        $amountIrr = number_format($payment->pay_amount, 0);

        $msg = "{$planEmoji} <b>{$plan->name}</b>\n\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> مبلغ قابل پرداخت: <b>{$amountIrr} تومان</b>\n";
        $msg .= "🧾 شناسه فاکتور: <code>{$payment->invoice_id}</code>\n\n";
        $msg .= "<blockquote><tg-emoji emoji-id=\"4915853119839011973\">⚠️</tg-emoji> لطفاً جهت پرداخت روی دکمه «انتقال به درگاه پرداخت» کلیک کنید.\n";
        $msg .= "<tg-emoji emoji-id=\"5116275208906343429\">‼️</tg-emoji> پس از پرداخت، اشتراک شما به صورت خودکار فعال می‌شود..</blockquote>";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💳 انتقال به درگاه پرداخت', url: $payment->invoice_url))
            ->addRow(InlineKeyboardButton::make('🔄 بررسی پرداخت', callback_data: "check_gateway_pay:{$payment->id}"))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: "select_plan_{$plan->id}", style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

        $bot->editMessageText($msg, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function handleCheckGatewayPayment(Nutgram $bot, User $user, int $paymentId): void
    {
        $payment = SubscriptionPayment::where('id', $paymentId)
            ->where('user_id', $user->id)
            ->first();

        if (!$payment) {
            $bot->answerCallbackQuery(text: '❌ فاکتوری با این مشخصات یافت نشد.', show_alert: true);
            return;
        }

        if ($payment->isPaid()) {
            $bot->answerCallbackQuery(text: '✅ این فاکتور قبلاً تایید و فعال شده است.', show_alert: true);
            return;
        }

        try {
            /** @var PaymentGatewayService $gateway */
            $gateway = app(PaymentGatewayService::class);
            $invoice = $gateway->getInvoice($payment->invoice_id);
            $status = $invoice['status'] ?? 'PENDING';
        } catch (\Throwable $e) {
            $bot->answerCallbackQuery(text: '❌ خطا در استعلام از درگاه. لطفا دقایقی بعد تلاش کنید.', show_alert: true);
            return;
        }

        if ($status === PaymentGatewayService::STATUS_PAID) {
            /** @var SubscriptionService $subService */
            $subService = app(SubscriptionService::class);
            $subService->fulfillPayment($payment);

            $plan = $payment->plan;
            $successMsg = "🎉 <b>پرداخت شما با موفقیت تایید شد!</b>\n\n";
            $successMsg .= "🎁 پلن <b>" . ($plan->name ?? '') . "</b> برای شما فعال گردید.\n";

            $bot->editMessageText($successMsg, parse_mode: 'HTML');
            $bot->answerCallbackQuery(text: '🎉 پرداخت با موفقیت تایید و پلن فعال شد!', show_alert: true);
            return;
        }

        if ($status === PaymentGatewayService::STATUS_UNDER_REVIEW) {
            $bot->answerCallbackQuery(
                text: '⏳ تراکنش شما در حال بررسی در درگاه است. لطفاً ۲ دقیقه دیگر مجدداً دکمه را لمس کنید.',
                show_alert: true
            );
            return;
        }

        if (in_array($status, [PaymentGatewayService::STATUS_REJECTED, PaymentGatewayService::STATUS_CANCELED], true)) {
            $payment->update(['status' => strtolower($status)]);
            $bot->answerCallbackQuery(text: '❌ این پرداخت لغو یا رد شده است.', show_alert: true);
            return;
        }

        $bot->answerCallbackQuery(
            text: '❌ هنوز پرداختی برای این فاکتور ثبت نشده است.',
            show_alert: true
        );
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
            ->addRow(InlineKeyboardButton::make('ترون ( TRX )', callback_data: "pay_crypto_trx_{$plan->id}", style: 'danger', icon_custom_emoji_id: '5391239186994967770'))
            ->addRow(InlineKeyboardButton::make('تون ( TON )', callback_data: "pay_crypto_ton_{$plan->id}", style: 'danger', icon_custom_emoji_id: '5265151230790884988'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_sub_crypto', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

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

        $payAmount = $payment['pay_amount'] ?? '---';
        $payAddress = $payment['pay_address'] ?? '---';
        $payCurrencyRaw = strtolower($payment['pay_currency'] ?? config('payments.nowpayments.pay_currency', '---'));

        $currencyEmoji = '';
        $currencyPersian = '';

        if ($payCurrencyRaw === 'trx' || $payCurrencyRaw === 'tron') {
            $currencyEmoji = '<tg-emoji emoji-id="5391239186994967770">💎</tg-emoji>';
            $currencyPersian = 'ترون';
        } elseif ($payCurrencyRaw === 'ton') {
            $currencyEmoji = '<tg-emoji emoji-id="5265151230790884988">💎</tg-emoji>';
            $currencyPersian = 'تون';
        } else {
            $currencyEmoji = '🪙';
            $currencyPersian = strtoupper($payCurrencyRaw);
        }

        $planEmoji = $this->getPlanEmoji($plan->name);

        $msg = "{$planEmoji} پرداخت {$payAmount} {$currencyPersian}{$currencyEmoji} برای فعالسازی پلن {$planEmoji} <b>{$plan->name}</b>\n\n";
        $msg .= "<tg-emoji emoji-id=\"5116648080787112958\">💰</tg-emoji> والت:\n";
        $msg .= "<code>{$payAddress}</code>\n\n";

        if (!empty($payment['payin_extra_id'])) {
            $msg .= "🧾 Memo/Tag:\n";
            $msg .= "<code>{$payment['payin_extra_id']}</code>\n\n";
        }

        $msg .= "<blockquote><tg-emoji emoji-id=\"4915853119839011973\">⚠️</tg-emoji> شما تنها 30 دقیقه فرصت دارید تا {$payAmount} {$currencyPersian} را به والت فوق واریز کنید.\n";
        $msg .= "<tg-emoji emoji-id=\"5116275208906343429\">‼️</tg-emoji> درصورتی که کمتر از مقدار فوق واریز شود، اشتراک برای شما فعال نخواهد شد.</blockquote>\n\n";
        $msg .= "<tg-emoji emoji-id=\"5116159438062879454\">🙏</tg-emoji> پس از واریز، روی دکمهی زیر کلیک کنید تا وضعیت پرداخت شما بررسی شود.";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('بررسی خرید', callback_data: 'check_pay:' . ($payment['payment_id'] ?? ''), style: 'danger', icon_custom_emoji_id: '6296367896398399651'))
            ->addRow(InlineKeyboardButton::make('بازگشت', callback_data: 'buy_subscription', style: 'danger', icon_custom_emoji_id: '5352759161945867747'));

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

    private function getPlanEmoji(?string $planName): string
    {
        $name = (string) $planName;

        if (mb_stripos($name, 'پرو') !== false || mb_stripos($name, 'pro') !== false) {
            return '<tg-emoji emoji-id="6244241334320762892">💎</tg-emoji>';
        }

        if (mb_stripos($name, 'پلاس') !== false || mb_stripos($name, 'plus') !== false) {
            return '<tg-emoji emoji-id="5433758796289685818">👑</tg-emoji>';
        }

        return '⭐';
    }

    private function planSectionTitle(SubscriptionPlan $plan): string
    {
        $planName = mb_strtolower(trim((string) $plan->name));

        return match ($planName) {
            'pro' => "<tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji> <b>اشتراک پرو</b> <tg-emoji emoji-id=\"6244241334320762892\">💎</tg-emoji>",
            'plus' => "<tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji> <b>اشتراک پلاس</b> <tg-emoji emoji-id=\"5433758796289685818\">👑</tg-emoji>",
            default => "<tg-emoji emoji-id=\"4929619512224909015\">🪱</tg-emoji> پلن انتخابشده: <b>{$plan->name}</b>",
        };
    }

    private function handleCheckPayment(Nutgram $bot, User $user, int $paymentId): void
    {
        $payment = SubscriptionPayment::where('payment_id', $paymentId)->first();

        if (!$payment) {
            $bot->answerCallbackQuery(text: '❌ فاکتوری با این مشخصات یافت نشد.', show_alert: true);
            return;
        }

        if ($payment->status === 'finished') {
            $bot->answerCallbackQuery(text: '✅ این فاکتور قبلاً تایید و فعال شده است.', show_alert: true);
            return;
        }

        try {
            /** @var NowPaymentsService $payments */
            $payments = app(NowPaymentsService::class);
            $statusInfo = $payments->getPaymentStatus($paymentId);
            $status = $statusInfo['payment_status'] ?? 'waiting';
        } catch (\Throwable $e) {
            $bot->answerCallbackQuery(text: '❌ خطا در ارتباط با درگاه پرداخت. لطفا مجدد تلاش کنید.', show_alert: true);
            return;
        }

        if ($status === 'finished') {
            DB::transaction(function () use ($payment, $statusInfo, $user) {
                $payment->update([
                    'status' => 'finished',
                    'meta' => array_merge($payment->meta ?? [], $statusInfo)
                ]);

                $plan = SubscriptionPlan::find($payment->subscription_plan_id);
                if (!$plan) {
                    throw new \Exception('Plan not found during activation');
                }

                $currentSubscription = Subscription::where('user_id', $user->id)
                    ->where('is_active', true)
                    ->first();

                if ($currentSubscription) {
                    if ($currentSubscription->subscription_plan_id === $plan->id) {
                        if ($currentSubscription->expires_at !== null && ($plan->duration_days ?? 0) > 0) {
                            $currentSubscription->expires_at = $currentSubscription->expires_at->addDays($plan->duration_days);
                            $currentSubscription->save();
                        }
                    } else {
                        $currentSubscription->update(['is_active' => false]);

                        Subscription::create([
                            'user_id' => $user->id,
                            'subscription_plan_id' => $plan->id,
                            'started_at' => now(),
                            'expires_at' => ($plan->duration_days ?? 0) > 0 ? now()->addDays($plan->duration_days) : null,
                            'is_active' => true,
                            'auto_renew' => false,
                        ]);
                    }
                } else {
                    Subscription::create([
                        'user_id' => $user->id,
                        'subscription_plan_id' => $plan->id,
                        'started_at' => now(),
                        'expires_at' => ($plan->duration_days ?? 0) > 0 ? now()->addDays($plan->duration_days) : null,
                        'is_active' => true,
                        'auto_renew' => false,
                    ]);
                }
            });

            $plan = SubscriptionPlan::find($payment->subscription_plan_id);
            $successMsg = "🎉 <b>پرداخت شما با موفقیت تایید شد!</b>\n\n";
            $successMsg .= "🎁 پلن <b>" . ($plan->name ?? '') . "</b> برای شما فعال گردید.\n";
            $successMsg .= "ممنون از اعتماد شما به ربات کرم پلاس! ❤️";

            $bot->editMessageText($successMsg, parse_mode: 'HTML');
            $bot->answerCallbackQuery(text: '🎉 پرداخت شما با موفقیت تایید و پلن فعال شد!', show_alert: true);
            return;
        }

        if (in_array($status, ['confirming', 'confirmed'], true)) {
            $bot->answerCallbackQuery(
                text: '⏳ تراکنش شما در شبکه بلاکچین رویت شده و در حال تایید است. لطفا ۲ الی ۵ دقیقه دیگر مجدداً دکمه بررسی را لمس کنید.',
                show_alert: true
            );
            return;
        }

        if (in_array($status, ['failed', 'invalid', 'expired'], true)) {
            $payment->update(['status' => $status]);
            $bot->answerCallbackQuery(text: '❌ این پرداخت منقضی شده یا پرداخت آن ناموفق بوده است.', show_alert: true);
            return;
        }

        $bot->answerCallbackQuery(
            text: '❌ هنوز تراکنش تایید نشده. لطفا دقایقی دیگر مجددا تلاش کنید.',
            show_alert: true
        );
    }
}
