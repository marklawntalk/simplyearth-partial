<?php

namespace App\Console\Commands;

use App\BoxRunReport;
use Illuminate\Console\Command;
use  Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;

class BoxRunSummaryNotifier extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'boxrunsummary:notify {schedule?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify for box run summary';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $schedule = $this->argument('schedule') ? Carbon::createFromFormat('Y-m-d', $this->argument('schedule')) : Carbon::today();

        $processed_count = BoxRunReport::whereDate('created_at', $schedule->format('Y-m-d'))->whereNotNull('processed_at')->count();
        $failed_count = BoxRunReport::whereDate('created_at', $schedule->format('Y-m-d'))->whereNotNull('failed_reason')->count();
        $skipped_count = BoxRunReport::whereDate('created_at', $schedule->format('Y-m-d'))->where('skipped', true)->count();
        $total_count = BoxRunReport::whereDate('created_at', $schedule->format('Y-m-d'))->count();
        $box_run_summary = [
            'schedule' => $schedule->format('F j, Y'),
            'total' => $total_count,
            'skipped' => $skipped_count,
            'processed' => $processed_count,
            'failed' => $failed_count,
            'unprocessed' => $total_count - ($processed_count + $failed_count + $skipped_count),
        ];

        Notification::route('slack', config('slack.box_run_summary'))
                ->notifyNow(new \App\Notifications\BoxRunSummaryNotification($box_run_summary));
    }
}
