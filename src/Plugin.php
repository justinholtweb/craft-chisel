<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * Chisel — Run allowlisted Artisan commands from the Craft CP — forms built from each command's signature, permission per command, full audit log.
 *
 * See PLAN.md for the build phases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Allowed commands', category: 'chisel'), Textarea::make('allowedCommands'))
                ->instructions(t('One command name per line. Nothing runs unless it is listed here or in config/craft/chisel.php.', category: 'chisel')),
            Field::make(t('Run on the queue', category: 'chisel'), Lightswitch::make('queueLongRunning'))
                ->instructions(t('Run commands as queued jobs with streamed output instead of in the request.', category: 'chisel')),
        ]);
    }
}
