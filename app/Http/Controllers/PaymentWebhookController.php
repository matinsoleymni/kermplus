<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\PaymentGatewayService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class PaymentWebhookController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $gatewayService,
        protected SubscriptionService $subscriptionService,
        protected Nutgram $bot
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Signature');

        // ۱. اعتبارسنجی امضای HMAC وب‌هوک
        if (! $this->gatewayService->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Payment webhook signature verification failed', [
                'received_signature' => $signature,
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $invoiceId = $request->input('invoice_id');
        $status = $request->input('status');

        if ($event !== 'invoice.status_changed') {
            return response()->json(['status' => 'ignored']);
        }

        // ۲. یافتن پرداخت متناظر در دیتابیس
        $payment = SubscriptionPayment::with(['user', 'plan'])
            ->where('invoice_id', $invoiceId)
            ->first();

        if (! $payment) {
            Log::error("Payment not found for gateway invoice: {$invoiceId}");
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // ۳. بررسی وضعیت و انجام عملیات
        match ($status) {
            PaymentGatewayService::STATUS_PAID => $this->handlePaid($payment),
            PaymentGatewayService::STATUS_REJECTED => $this->handleRejected($payment),
            PaymentGatewayService::STATUS_CANCELED => $this->handleCanceled($payment),
            default => Log::info("Unhandled gateway status: {$status} for invoice {$invoiceId}"),
        };

        return response()->json(['status' => 'ok']);
    }

    /**
     * پردازش پرداخت موفق و ارسال پیام تلگرام به کاربر
     */
    protected function handlePaid(SubscriptionPayment $payment): void
    {
        // جلوگیری از پردازش تکراری وب‌هوک
        if ($payment->isPaid()) {
            return;
        }

        // فعال‌سازی / تمدید اشتراک در دیتابیس
        $subscription = $this->subscriptionService->fulfillPayment($payment);

        $user = $payment->user;
        $plan = $payment->plan;

        if (! $user || ! $user->telegram_id) {
            return;
        }

        $durationText = ($plan->duration_days ?? 0) > 0 ? "{$plan->duration_days} روز" : 'نامحدود';
        $expiresAtText = $subscription->expires_at
            ? $subscription->expires_at->format('Y-m-d H:i')
            : 'دائمی / نامحدود';

        $msg = "🎉 <b>پرداخت شما با موفقیت تایید شد!</b>\n\n";
        $msg .= "👑 پلن <b>{$plan->name}</b> با موفقیت برای حساب شما فعال گردید.\n\n";
        $msg .= "🧾 شناسه پرداخت: <code>#{$payment->id}</code>\n\n";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    text: '🪱 ورود به منوی اصلی',
                    callback_data: 'main_menu'
                )
            );

        $this->sendTelegramNotification($user->telegram_id, $msg, $keyboard);
    }

    /**
     * پردازش پرداخت رد شده و اطلاع‌رسانی به کاربر
     */
    protected function handleRejected(SubscriptionPayment $payment): void
    {
        $payment->update(['status' => SubscriptionPayment::STATUS_REJECTED]);

        $user = $payment->user;
        if (! $user || ! $user->telegram_id) {
            return;
        }

        $plan = $payment->plan;
        $msg = "❌ <b>پرداخت شما تایید نشد.</b>\n\n";
        $msg .= "متأسفانه پرداخت فاکتور مربوط به پلن <b>{$plan?->name}</b> توسط درگاه رد شد.\n";

        $this->sendTelegramNotification($user->telegram_id, $msg);
    }

    /**
     * پردازش پرداخت لغو شده
     */
    protected function handleCanceled(SubscriptionPayment $payment): void
    {
        $payment->update(['status' => SubscriptionPayment::STATUS_CANCELED]);
    }

    /**
     * ارسال امن پیام تلگرام بدون ایجاد وقفه در وب‌هوک
     */
    protected function sendTelegramNotification(int $telegramId, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $telegramId,
                parse_mode: 'HTML',
                reply_markup: $keyboard
            );
        } catch (\Throwable $e) {
            Log::warning("Failed to send Telegram notification to user {$telegramId}: " . $e->getMessage());
        }
    }
}
