<?php

declare(strict_types=1);

use JustinHoltWeb\Chisel\Settings;

it('has sensible defaults', function () {
    $settings = new Settings;

    expect($settings->allowedCommands)->toBe(null);
});
