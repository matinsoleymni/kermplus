<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\SubscriptionPayment;

class NowPaymentsWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $signature = $request->header('x-nowpayments-sig');
        $secret = config('payments.nowpayments.ipn_secret');

        if (!$signature || !$secret) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        ksort($payload);
        $sortedJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $calculatedSignature = hash_hmac('sha512', $sortedJson, $secret);

        if (!hash_equals($signature, $calculatedSignature)) {
            Log::warning('NOWPayments Webhook: Invalid Signature Attempt.');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $paymentStatus = $request->input('payment_status');
        $orderId = $request->input('order_id');
        $paymentId = $request->input('payment_id');
        $order = SubscriptionPayment::where('invoice_id', $orderId)->first();

        if (!$order) {
            Log::error("NOWPayments Webhook: Order not found for ID {$orderId}");
            return response()->json(['error' => 'Order not found'], 404);
        }

        switch ($paymentStatus) {
            case 'finished':
                if ($order->status !== 'completed') {
                    $order->update([
                        'status' => 'completed',
                        'transaction_id' => $paymentId
                    ]);
                }
                break;

            case 'failed':
            case 'expired':
                $order->update(['status' => 'failed']);
                break;

            case 'waiting':
            case 'confirming':
            case 'confirmed':
                $order->update(['status' => 'processing']);
                break;
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
