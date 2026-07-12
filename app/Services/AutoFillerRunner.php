<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoFillerRunner
{
    protected string $goBaseUrl;

    public function __construct()
    {
        $this->goBaseUrl = config('services.go_autofill.url', 'http://form:8084');
    }

    /**
     * Dispatch a standard form filling job to the Go microservice.
     */
    public function fill(string $name, string $phone): array
    {
        $payload = [
            'phone_number' => $phone,
            'full_name'    => $name,
        ];

        return $this->dispatchToGo('/api/fill', $payload);
    }

    /**
     * Dispatch a registration form filling job to the Go microservice.
     */
    public function register(string $name, string $phone, string $email): array
    {
        $payload = [
            'phone_number' => $phone,
            'full_name'    => $name,
            'email'        => $email,
        ];

        return $this->dispatchToGo('/api/register', $payload);
    }

    /**
     * Shared internal helper to handle the HTTP communication with the Go API.
     */
    private function dispatchToGo(string $endpoint, array $payload): array
    {
        $url = $this->goBaseUrl . $endpoint;
        $report = [];
        $summary = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($url, $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $taskId = $responseData['task_id'] ?? 'unknown';

                $summary = sprintf(
                    'Success: Job successfully dispatched to Go %s. Task ID: %s',
                    $endpoint,
                    $taskId
                );

                $report[] = [
                    'endpoint' => $endpoint,
                    'status'   => 'dispatched',
                    'task_id'  => $taskId,
                    'message'  => 'Task accepted asynchronously by Go worker.',
                ];

                $this->writeLog($summary, $report);

                return [
                    'success' => true,
                    'task_id' => $taskId,
                    'summary' => $summary,
                ];
            }

            throw new \Exception("Go Service returned HTTP status " . $response->status());

        } catch (\Throwable $e) {
            $summary = "Go Integration Error ({$endpoint}): " . $e->getMessage();
            Log::error($summary);

            $report[] = [
                'endpoint' => $endpoint,
                'status'   => 'error',
                'message'  => $e->getMessage(),
            ];

            $this->writeLog($summary, $report);

            return [
                'success' => false,
                'task_id' => null,
                'summary' => $summary,
            ];
        }
    }

    /**
     * Standard centralized logging handler
     */
    private function writeLog(string $summary, array $report): void
    {
        $date = date('Y-m-d');
        $logPath = storage_path("logs/autofill-go-gateway-{$date}.log");
        $logContent = "=== Go API Gateway Sync: " . date('c') . " ===\n";
        $logContent .= $summary . "\n";
        foreach ($report as $r) {
            $logContent .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
        $logContent .= "\n";

        try {
            file_put_contents($logPath, $logContent, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            Log::error('Failed to write Go Gateway wrapper log: ' . $e->getMessage());
        }
    }
}
