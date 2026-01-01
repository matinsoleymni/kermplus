<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // Commands are auto-discovered from Commands/ but you can register here if needed
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        $expr = config('autofill.schedule', 'daily');

        $command = 'autofiller:run';

        // allow simple names like 'daily', 'hourly', 'everyMinute', 'weekly'
        switch ($expr) {
            case 'everyMinute':
                $schedule->command($command)->everyMinute();
                break;
            case 'hourly':
                $schedule->command($command)->hourly();
                break;
            case 'daily':
                $schedule->command($command)->daily();
                break;
            case 'weekly':
                $schedule->command($command)->weekly();
                break;
            default:
                // If it's a cron expression (contains spaces or *), use it directly
                if (is_string($expr) && (strpos($expr, ' ') !== false || strpos($expr, '*') !== false)) {
                    $schedule->command($command)->cron($expr);
                } else {
                    // fallback to daily
                    $schedule->command($command)->daily();
                }
                break;
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
