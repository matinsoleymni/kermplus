<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoFillerRunner;

class RunAutoFormFiller extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autofiller:run {--single= : optionally run a single URL (pass the url)} {--name= : optionally override the default name} {--phone= : optionally override the default phone}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run AutoFormFiller against configured sites (from config/autofill.php)';

    public function handle(AutoFillerRunner $runner)
    {
        $single = $this->option('single');
        $customName = $this->option('name');
        $customPhone = $this->option('phone');

        $sites = config('autofill.sites', []);
        if ($single) {
            $sites = [$single];
        }

        if (empty($sites)) {
            $this->error('No sites configured in config/autofill.php');
            return 1;
        }

        // Use custom values if provided, otherwise use config
        $name = $customName ?? config('autofill.name', 'Test User');
        $phone = $customPhone ?? config('autofill.phone');
        $sleepUs = (int) config('autofill.sleep_us', 100000);
        $debug = (bool) config('autofill.debug', false);

        $report = [];
        $result = $runner->run($sites, $name, $phone, $sleepUs, $debug);

        foreach ($result['report'] as $row) {
            if (isset($row['id'], $row['status'], $row['url'])) {
                $this->info("[" . $row['id'] . "/" . $result['stats']['total'] . "] {$row['status']} : {$row['url']}" . (!empty($row['message']) ? " ({$row['message']})" : ''));
            } elseif (is_string($row)) {
                $this->info($row);
            }
        }

        $this->info($result['summary']);
        if ($result['log_path']) {
            $this->info('Report written to: ' . $result['log_path']);
        }

        return 0;
    }
}
