<?php

namespace SimpleStatsIo\LaravelClient\Commands;

use Illuminate\Console\Command;

class SimplestatsClientCommand extends Command
{
    public $signature = 'simplestats-client';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
