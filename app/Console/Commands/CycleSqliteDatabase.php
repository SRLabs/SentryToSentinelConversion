<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CycleSqliteDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cycle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Sentinel Conversion migrations against a fresh copy of the Sentry schema';

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
        // Create a new copy of the Sentry database
        copy(database_path('sentry.sqlite'), database_path('sentinel.sqlite'));
        $this->info("Created " . database_path('sentinel.sqlite'));

        // Run the outstanding migrations
        $this->call('migrate', [
            '--database' => 'sqlite-sentinel'
        ]);
    }
}
