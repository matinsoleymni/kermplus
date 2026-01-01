<?php

namespace App\Jobs;

use App\Services\EmailBomberService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEmailBombJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $email;
    public int $batchSize;
    public int $totalBatches;
    public int $intervalMinutes;

    public function __construct(string $email, int $batchSize, int $totalBatches = 1, int $intervalMinutes = 0)
    {
        $this->email = $email;
        $this->batchSize = $batchSize;
        $this->totalBatches = $totalBatches;
        $this->intervalMinutes = $intervalMinutes;
    }

    public function handle(EmailBomberService $service)
    {
        $result = $service->sendBomb($this->email, $this->batchSize, $this->totalBatches, $this->intervalMinutes);

        if (isset($result['error'])) {
            Log::info('Email bomb skipped/failed', [
                'email' => $this->email,
                'batch_size' => $this->batchSize,
                'total_batches' => $this->totalBatches,
                'interval_minutes' => $this->intervalMinutes,
                'error' => $result['error'],
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
            ]);
        }
    }
}
