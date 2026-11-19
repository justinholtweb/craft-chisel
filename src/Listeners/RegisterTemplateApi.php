<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Listeners;

use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use JustinHoltWeb\Chisel\Templates\ChiselVariable;

/**
 * `chisel` global for Twig and Blade alike (Craft feeds template globals to both).
 */
class RegisterTemplateApi
{
    public function handle(TemplateGlobalsResolving $event): void
    {
        $event->globals['chisel'] = app(ChiselVariable::class);
    }
}
