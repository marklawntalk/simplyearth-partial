<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\DailyAverage as DailyAverageModel;
use Illuminate\Support\Carbon;

class DailyAverage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dailyaverage:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Daily Average';

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
        DailyAverageModel::generateByDate(Carbon::today());
    }
}
