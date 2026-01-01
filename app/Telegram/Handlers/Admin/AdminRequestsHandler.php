<?php

namespace App\Telegram\Handlers\Admin;

use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;

class AdminRequestsHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $jobs = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($jobs->isEmpty()) {
            $bot->sendMessage('⛔️ درخواستی ثبت نشده است.');
            return;
        }

        $msg = "📝 آخرین درخواست‌ها:\n";
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $type = '';
            $target = '';
            $meta = '';
            if (isset($payload['displayName']) && $payload['displayName'] === 'SendSmsBombJob') {
                $type = '💣 SMS';
                $target = $payload['data']['phone'] ?? '';
                $batch = $payload['data']['batchSize'] ?? null;
                $batches = $payload['data']['totalBatches'] ?? null;
                $interval = $payload['data']['intervalMinutes'] ?? null;
                if ($batch && $batches) {
                    $intervalText = $interval !== null ? " @{$interval}m" : '';
                    $meta = " ({$batch}x{$batches}{$intervalText})";
                }
            } elseif (isset($payload['displayName']) && $payload['displayName'] === 'SendEmailBombJob') {
                $type = '📧 Email';
                $target = $payload['data']['email'] ?? '';
            } else {
                continue;
            }
            $msg .= "{$type} → {$target}{$meta}\n";
        }

        $bot->sendMessage($msg);
    }
}
