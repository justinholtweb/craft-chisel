<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Console;

use Illuminate\Console\Command;
use JustinHoltWeb\Chisel\Commands\Catalog;

class RefreshCommand extends Command
{
    protected $signature = 'craft:chisel:refresh';

    protected $description = 'Re-read every Artisan command’s signature for Chisel’s control panel forms.';

    public function handle(Catalog $catalog): int
    {
        $this->components->info(sprintf('Chisel knows %d commands.', $catalog->refresh()));

        return self::SUCCESS;
    }
}
