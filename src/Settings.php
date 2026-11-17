<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /**
     * Allowed commands. One command name per line. Nothing runs unless it is listed here or in config/craft/chisel.php.
     *
     * @var string|list<string>|null
     */
    public string|array|null $allowedCommands = null;

    /** Run on the queue. Run commands as queued jobs with streamed output instead of in the request. */
    public bool $queueLongRunning = true;
}
