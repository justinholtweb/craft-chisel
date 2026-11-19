<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Settings saved from Chisel → Settings (admin + elevated session) into project config.
 * `config/craft/chisel.php` overrides any of them, and also holds the switches that
 * are deliberately config-file-only — see {@see Chisel::config()}.
 *
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /**
     * Commands people may run, by name. Nothing runs unless it is listed here (or in the
     * config file's `allowedCommands`, which replaces this list).
     *
     * @var string|list<string>|null
     */
    public string|array|null $allowedCommands = [];

    /** Runs older than this many days are deleted (with their output). 0 keeps them forever. */
    public int $historyRetentionDays = 90;

    /** Output kept per run, in KB. Anything beyond is dropped with a note. */
    public int $maxOutputKb = 512;

    /** How long a command may run, in seconds, before the queue worker kills it. */
    public int $timeout = 600;

    /** Show Chisel in the sidebar. */
    public bool $showInCpNav = true;

    /** What the sidebar link says. Null uses “Chisel”. */
    public ?string $cpNavLabel = null;

    /** A Craft icon name or `set:name` from a custom icon set. Null uses Chisel's own icon. */
    public ?string $cpNavIcon = null;

    /**
     * The allowlist as clean, unique command names.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        return self::commandList($this->allowedCommands);
    }

    /**
     * @param  string|array<array-key, mixed>|null  $value  one name per line (or comma), or a list
     * @return list<string>
     */
    public static function commandList(string|array|null $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $names = [];

        foreach ($items ?: [] as $item) {
            $name = is_string($item) ? strtolower(trim($item)) : '';
            if ($name !== '' && Commands\CommandPolicy::isValidName($name)) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    public function navLabel(): ?string
    {
        $label = trim((string) $this->cpNavLabel);

        return $label === '' ? null : $label;
    }

    public function navIcon(): ?string
    {
        $icon = trim((string) $this->cpNavIcon);

        return $icon !== '' && self::isIcon($icon) ? $icon : null;
    }

    public static function isIcon(string $icon): bool
    {
        if ($custom = IconSets::parse($icon)) {
            $svg = rescue(fn () => app(IconSets::class)->getIconSetByHandle($custom[0])?->getSvg($custom[1]), null, false);

            return is_string($svg) && stripos($svg, '<svg') !== false;
        }

        return (bool) preg_match('/^[\da-z\-]+$/D', $icon) && is_file(Icons::resolveIconPath($icon));
    }

    /**
     * @return array<string, list<string>>
     */
    public function getRules(): array
    {
        return [
            'allowedCommands' => ['nullable'],
            'historyRetentionDays' => ['integer', 'min:0', 'max:3650'],
            'maxOutputKb' => ['integer', 'min:16', 'max:8192'],
            'timeout' => ['integer', 'min:10', 'max:86400'],
            'cpNavLabel' => ['nullable', 'string', 'max:100'],
            'cpNavIcon' => ['nullable', 'string', 'max:100', 'regex:/^([a-zA-Z][\w\-]*:)?[\w\-]+$/'],
        ];
    }
}
