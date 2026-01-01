<?php

namespace App\Jobs;

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

    public function __construct(string $phone, int $batchSize, int $totalBatches = 1, int $intervalMinutes = 0)
    {
        $this->phone = $phone;
        $this->batchSize = $batchSize;
        $this->totalBatches = $totalBatches;
        $this->intervalMinutes = $intervalMinutes;
    }

    public function handle(SmsBomberService $service)
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
    }
}
