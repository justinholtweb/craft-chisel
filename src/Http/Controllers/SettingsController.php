<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Plugins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Commands\Catalog;
use JustinHoltWeb\Chisel\Commands\CommandPolicy;
use JustinHoltWeb\Chisel\Settings;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Chisel → Settings. Admins only (whatever `requireAdmin` says), an elevated session to
 * save, and only where admin changes are allowed. The allowlist offers every known command
 * with its risk; forbidden commands can't be ticked and sensitive ones belong in the
 * config file. Anything set in `config/craft/chisel.php` is shown but not editable.
 */
class SettingsController
{
    use RendersScreens;

    private const array FIELDS = ['historyRetentionDays', 'maxOutputKb', 'timeout', 'showInCpNav', 'cpNavLabel', 'cpNavIcon'];

    public function __construct(
        private readonly Chisel $chisel,
        private readonly Catalog $catalog,
        private readonly Settings $settings,
    ) {}

    public function edit(Request $request): CpScreenResponse
    {
        $user = self::user($request);
        abort_unless($user->isAdmin(), 403);

        $allowed = $this->chisel->allowlist();
        $sensitive = $this->chisel->sensitiveAllowlist();
        $old = $request->session()->getOldInput();
        $checked = is_array($old['allowedCommands'] ?? null) ? $old['allowedCommands'] : $allowed;
        $suggested = CommandPolicy::suggested();

        $groups = [];
        $names = array_unique([...array_keys($this->catalog->all()), ...$allowed]);
        sort($names);
        foreach ($names as $name) {
            $risk = CommandPolicy::classify($name);
            $group = str_contains($name, ':') ? substr($name, 0, (int) strrpos($name, ':')) : t('General', category: 'chisel');
            $groups[$group][] = [
                'name' => $name,
                'description' => $this->catalog->get($name)->description ?? t('Not found on this install.', category: 'chisel'),
                'risk' => $risk,
                'checked' => in_array($name, $checked, true) || in_array($name, $sensitive, true),
                'disabled' => in_array($risk, [CommandPolicy::FORBIDDEN, CommandPolicy::SENSITIVE], true),
                'suggested' => in_array($name, $suggested, true),
            ];
        }

        /** @var ViewErrorBag $bag */
        $bag = $request->session()->get('errors') ?? new ViewErrorBag;

        return $this->screen($request, 'settings', t('Settings', category: 'chisel'), template('chisel/_settings', [
            'groups' => $groups,
            'values' => array_merge(
                array_combine(self::FIELDS, array_map(fn (string $f) => $this->settings->{$f}, self::FIELDS)),
                array_intersect_key($old, array_flip(self::FIELDS)),
            ),
            'errors' => $bag->getBag('default')->toArray(),
            'readOnly' => ! Cms::config()->allowAdminChanges,
            'allowlistFromConfig' => $this->chisel->allowlistFromConfig(),
            'fromConfig' => array_values(array_filter(self::FIELDS, fn (string $f) => Chisel::config($f) !== null)),
            'action' => cp_url('chisel/settings'),
            'sensitive' => $sensitive,
            'requireAdmin' => Chisel::config('requireAdmin', true) !== false,
            'queue' => $this->chisel->queue(),
            ...$this->elevationVars($user),
        ]), []);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($user->isAdmin(), 403);
        abort_unless(Cms::config()->allowAdminChanges, 403);
        $back = cp_url('chisel/settings');
        $keep = fn (RedirectResponse $r) => $r->withInput($request->except(['password', '_token']));

        if (! $this->chisel->isElevated()) {
            $password = $request->input('password');
            if (! is_string($password) || ! $this->chisel->elevate($user, $password)) {
                return $keep(redirect($back))->withErrors(['password' => $this->chisel->elevationLockedOut($user)
                    ? t('Too many wrong passwords. Try again in a minute.', category: 'chisel')
                    : t('Enter your password to confirm it’s you.', category: 'chisel')]);
            }
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            if (Chisel::config($field) === null && $request->has($field)) {
                $values[$field] = $request->input($field);
            }
        }
        foreach (['historyRetentionDays', 'maxOutputKb', 'timeout'] as $int) {
            if (isset($values[$int]) && is_numeric($values[$int])) {
                $values[$int] = (int) $values[$int];
            }
        }
        if (array_key_exists('showInCpNav', $values)) {
            $values['showInCpNav'] = in_array($values['showInCpNav'], ['1', 'on', 'true', 1, true], true);
        }

        if (! $this->chisel->allowlistFromConfig()) {
            $requested = $request->input('allowedCommands', []);
            $names = is_array($requested) ? $requested : [];
            $refused = [];
            $list = [];
            foreach (Settings::commandList(array_values(array_filter($names, 'is_string'))) as $name) {
                if (in_array(CommandPolicy::classify($name), [CommandPolicy::FORBIDDEN, CommandPolicy::SENSITIVE], true)) {
                    $refused[] = $name;
                } else {
                    $list[] = $name;
                }
            }
            if ($refused !== []) {
                return $keep(redirect($back))->withErrors(['allowedCommands' => t('These can’t be allowlisted here: {names}. Forbidden commands never run; sensitive ones go in `sensitiveCommands` in `config/craft/chisel.php`.', ['names' => implode(', ', $refused)], category: 'chisel')]);
            }
            $values['allowedCommands'] = $list;
        }

        $plugin = Plugins::getPlugin('chisel') ?? abort(404);

        try {
            $saved = Plugins::savePluginSettings($plugin, $values);
        } catch (Throwable $e) {
            Log::warning('Chisel: settings save failed.', ['exception' => $e::class]);

            return $keep(redirect($back))->with('error', t('Couldn’t save the settings. Try again.', category: 'chisel'));
        }

        if (! $saved) {
            $errors = $plugin->getSettings()?->errors()->getMessages() ?? [];

            return $keep(redirect($back))->withErrors(array_map(fn ($m) => is_array($m) ? implode(' ', $m) : (string) $m, $errors));
        }

        Log::info('Chisel: settings saved.', ['userId' => $user->getCraftUserId(), 'allowlist' => $values['allowedCommands'] ?? null]);

        return redirect($back)->with('success', t('Settings saved.', category: 'chisel'));
    }
}
