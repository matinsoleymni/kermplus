<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessApkScanResult;

class ApkWebhookController extends Controller
{
    public function handle(Request $request, $secret)
    {
        // 1. اعتبارسنجی Secret موجود در URL
        $expectedSecret = config('services.apk_builder.webhook_secret', env('APK_WEBHOOK_SECRET'));

        if ($secret !== $expectedSecret) {
            abort(403, 'Unauthorized');
        }

        // 2. دریافت و اعتبارسنجی دیتای ارسالی از سرویس Go
        $data = $request->validate([
            'file'      => 'required|string',
            'status'    => 'required|string',
            'verdict'   => 'nullable|string',
            'error'     => 'nullable|string',
            'permalink' => 'nullable|string',
            'sha256'    => 'nullable|string',
        ]);

        // 3. ارسال به صف برای پردازش (Fire and Forget)
        ProcessApkScanResult::dispatch($data);

        // 4. پاسخ سریع به سرویس Go
        return response()->json(['message' => 'received'], 200);
    }
}
