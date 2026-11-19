<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JustinHoltWeb\Chisel\Commands\Catalog;

/**
 * Re-reads every command's signature in the queue worker, where all commands exist.
 */
class RefreshCatalog implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** A cleared job can't block refreshes for longer than this. */
    public int $uniqueFor = 300;

    public function handle(Catalog $catalog): void
    {
        if (app()->runningInConsole()) {
            $catalog->refresh();
        }
    }
}
