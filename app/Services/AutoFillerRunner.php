<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AutoFillerRunner
{
    /**
     * Run autofill locally (no external /auto-fillter calls).
     */
    public function run(array $sites, string $name, string $phone, int $sleepUs = 100000, bool $debug = false): array
    {
        $report = [];
        $stats = ['success' => 0, 'failed' => 0, 'total' => count($sites)];
        $summary = null;
        $errors = [];

        if (empty($sites)) {
            return [
                'stats' => $stats,
                'report' => [],
                'summary' => 'هیچ سایتی پیکربندی نشده است.',
                'log_path' => null,
            ];
        }

        foreach ($sites as $index => $url) {
            try {
                $bot = new AutoFormFiller();
                $bot->setDebug($debug);
                $result = $bot->submitForm($url, $phone, $name);

                $status = $result['status'] ? 'success' : 'failed';
                if ($result['status']) {
                    $stats['success']++;
                } else {
                    $stats['failed']++;
                }

                $report[] = [
                    'id' => $index + 1,
                    'url' => $url,
                    'status' => $status,
                    'message' => $result['message'] ?? null,
                    'sent_data' => $result['sent_data'] ?? null,
                ];
            } catch (\Throwable $e) {
                $stats['failed']++;
                $errors[] = $e->getMessage();
                $report[] = [
                    'id' => $index + 1,
                    'url' => $url,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'sent_data' => [],
                ];
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        if (!$summary && !empty($errors)) {
            $summary = 'Errors: ' . implode(' | ', $errors);
        }

        $summary = $summary ?: sprintf(
            'Summary: success=%d, failed=%d, total=%d',
            $stats['success'],
            $stats['failed'],
            $stats['total']
        );

        $logPath = $this->writeLog($summary, $report);

        return [
            'stats' => $stats,
            'report' => $report,
            'summary' => $summary,
            'log_path' => $logPath,
        ];
    }

    private function writeLog(string $summary, array $report): ?string
    {
        $date = date('Y-m-d');
        $logPath = storage_path("logs/autofill-{$date}.log");
        $logContent = "=== AutoFormFiller Run: " . date('c') . " ===\n";
        $logContent .= $summary . "\n\n";
        foreach ($report as $r) {
            $logContent .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
        $logContent .= "\n";

        try {
            file_put_contents($logPath, $logContent, FILE_APPEND | LOCK_EX);
            return $logPath;
        } catch (\Throwable $e) {
            Log::error('Failed to write AutoFormFiller log: ' . $e->getMessage());
            return null;
        }
    }
}
