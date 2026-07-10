<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NowPaymentsWebhookController;

Route::get('/', function () {
    return view('welcome');
});

// Route::get('/run-competition', function (Request $request) {
//     // Run the autofiller via Artisan command. Use ?single=<url> to run one URL for testing.
//     $single = $request->query('single');

//     if ($single) {
//         Artisan::call('autofiller:run', ['--single' => $single]);
//         return response()->json(['status' => 'ok', 'message' => 'Ran autofiller for single URL', 'url' => $single]);
//     }

//     // Run full job (synchronous). In production, rely on scheduler (cron) instead.
//     Artisan::call('autofiller:run');
//     return response()->json(['status' => 'ok', 'message' => 'Autofiller run triggered; check storage/logs/autofill-*.log']);
// });


Route::post('/payments/nowpayments/webhook', [NowPaymentsWebhookController::class, 'handle']);
