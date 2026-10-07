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
        Commands\AutoPurchase::class,
        Commands\DsoAlert::class,
        Commands\ResetDB::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if (config('deployment.backup_enabled')) $schedule->command('erp:backup')->dailyAt('02:00')->withoutOverlapping();
        if (config('commercial.enabled') && config('compliance.enabled') && config('deployment.dispatch_worker_confirmed')) {
            $schedule->command('erp:dispatch-documents')->everyMinute()->withoutOverlapping();
        }
        if (config('commercial.enabled')) $schedule->command('commercial:prune-drafts')->dailyAt('03:30')->withoutOverlapping();
        // Global legacy purchase/alert jobs and the test mailer are not production schedules.

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
