<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Console;

use Illuminate\Console\Command;
use JustinHoltWeb\Chisel\Runs\History;

class PruneCommand extends Command
{
    protected $signature = 'craft:chisel:prune';

    protected $description = 'Delete Chisel runs older than the retention setting, and close runs abandoned by a dead worker.';

    public function handle(History $history): int
    {
        [$deleted, $abandoned] = $history->prune();
        $this->components->info(sprintf('Deleted %d old runs; closed %d abandoned runs.', $deleted, $abandoned));

        return self::SUCCESS;
    }
}
