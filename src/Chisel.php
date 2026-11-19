<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel;

use CraftCms\Cms\Auth\AuthMethods;
use CraftCms\Cms\Auth\Impersonation;
use CraftCms\Cms\Cms;
use CraftCms\Cms\User\Contracts\CraftUser;
use CraftCms\Cms\User\Elements\User as UserElement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use JustinHoltWeb\Chisel\Commands\Catalog;
use JustinHoltWeb\Chisel\Commands\CommandPolicy;
use JustinHoltWeb\Chisel\Commands\CommandSpec;

use function CraftCms\Cms\t;

/**
 * Every “may this happen?” decision in one place, so the CP, the queued job and the
 * template helper can't disagree. Everything fails closed.
 *
 * Config-file-only switches (`config/craft/chisel.php`), read straight from config so
 * nothing saved in the control panel or project config can flip them:
 * - `enabled` (default true): master switch.
 * - `enableInProduction` (default false): allow running when APP_ENV is production or
 *   `allowAdminChanges` is off. History stays readable either way.
 * - `sensitiveCommands` (default []): commands that reveal configuration or manage
 *   accounts/plugins, allowed only by being listed here.
 * - `requireAdmin` (default true): only admins. When false, people with the Chisel
 *   permissions can use it too.
 * - `allowedOptions` (default []): `['command' => ['option', …]]`, value options to offer
 *   beyond the safe built-in list. Never re-enables a forbidden option.
 * - `elevationSeconds` (default 300): how long a password confirmation lasts.
 * - `queue` (default `chisel`) / `connection` (default: the app's default).
 */
class Chisel
{
    public const string PERMISSION_RUN = 'chisel:runCommands';

    public const string PERMISSION_HISTORY = 'chisel:viewHistory';

    /** How long a password confirmation lasts unless `elevationSeconds` says otherwise. */
    public const int FALLBACK_ELEVATION_SECONDS = 300;

    private const int ELEVATION_ATTEMPTS_PER_MINUTE = 5;

    private const string ELEVATION_SESSION_KEY = 'chisel.elevatedAt';

    public function __construct(private readonly Settings $settings, private readonly Catalog $catalog) {}

    public static function config(string $key, mixed $default = null): mixed
    {
        return config("craft.chisel.$key", $default);
    }

    /** Why commands can't run in this environment right now, or null when they can. */
    public function disabledReason(): ?string
    {
        if (self::config('enabled', true) === false) {
            return t('Chisel is switched off in `config/craft/chisel.php`.', category: 'chisel');
        }

        if (self::config('enableInProduction', false) !== true) {
            if (app()->isProduction()) {
                return t('Chisel doesn’t run commands in production unless `enableInProduction` is set in `config/craft/chisel.php`.', category: 'chisel');
            }
            if (! Cms::config()->allowAdminChanges) {
                return t('Chisel doesn’t run commands where `allowAdminChanges` is off, unless `enableInProduction` is set in `config/craft/chisel.php`.', category: 'chisel');
            }
        }

        return null;
    }

    public function queue(): string
    {
        $queue = self::config('queue', 'chisel');

        return is_string($queue) && preg_match('/^[\w\-.]{1,64}$/D', $queue) ? $queue : 'chisel';
    }

    public function connection(): ?string
    {
        $connection = self::config('connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /** Whether Craft's web queue runner would also pick up Chisel's jobs (it must not). */
    public function queueSharedWithWebRunner(): bool
    {
        $config = Cms::config();

        return $config->runQueueAutomatically && in_array($this->queue(), [$config->queueName, $config->lowPriorityQueueName], true);
    }

    public function allowlistFromConfig(): bool
    {
        return self::config('allowedCommands') !== null;
    }

    /** @return list<string> */
    public function allowlist(): array
    {
        return $this->settings->allowlist();
    }

    /** @return list<string> */
    public function sensitiveAllowlist(): array
    {
        $value = self::config('sensitiveCommands', []);

        return Settings::commandList(is_array($value) || is_string($value) ? $value : []);
    }

    /**
     * How dangerous a command is: its canonical name and every alias it answers to are
     * classified, and the strictest wins. Unknown names are forbidden.
     */
    public function risk(string $name): string
    {
        $spec = $this->catalog->get($name);

        return $spec === null ? CommandPolicy::FORBIDDEN : CommandPolicy::classifyAll($spec->names());
    }

    /** For the allowlist editor: a known command's {@see risk()}, else its name's own class. */
    public function listedRisk(string $name): string
    {
        return $this->catalog->get($name) !== null ? $this->risk($name) : CommandPolicy::classify($name);
    }

    /**
     * Value options the config file's `allowedOptions` adds for one command.
     *
     * @return list<string>
     */
    public function extraOptions(string $name): array
    {
        $all = self::config('allowedOptions', []);
        $list = is_array($all) ? ($all[$name] ?? []) : [];

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** The command's signature as Chisel offers it (policy applied), or null. */
    public function spec(string $name): ?CommandSpec
    {
        return $this->catalog->get($name)?->withPolicy($this->extraOptions($name));
    }

    /**
     * Why a command can't be run, or null when it can (permissions aside). Names are exact
     * canonical names: an alias, abbreviation or different case is not a command here.
     */
    public function commandRefusal(string $name): ?string
    {
        if (! CommandPolicy::isValidName($name)) {
            return t('That isn’t a command name.', category: 'chisel');
        }

        if (CommandPolicy::classify($name) === CommandPolicy::FORBIDDEN) {
            return t('`{name}` can never be run from the control panel.', ['name' => $name], category: 'chisel');
        }

        $spec = $this->catalog->get($name);
        if ($spec === null) {
            return t('`{name}` isn’t a command on this install (aliases and abbreviations don’t count; the command list may need refreshing).', ['name' => $name], category: 'chisel');
        }

        $risk = CommandPolicy::classifyAll($spec->names());

        if ($risk === CommandPolicy::FORBIDDEN) {
            return t('`{name}` can never be run from the control panel.', ['name' => $name], category: 'chisel');
        }

        if ($risk === CommandPolicy::SENSITIVE) {
            if (! in_array($name, $this->sensitiveAllowlist(), true)) {
                return t('`{name}` can reveal configuration or manage accounts, so it runs only when listed in `sensitiveCommands` in `config/craft/chisel.php`.', ['name' => $name], category: 'chisel');
            }
        } elseif (! in_array($name, $this->allowlist(), true) && ! in_array($name, $this->sensitiveAllowlist(), true)) {
            return t('`{name}` isn’t on the allowlist.', ['name' => $name], category: 'chisel');
        }

        if ($this->spec($name) === null) {
            return t('`{name}` needs an input Chisel never accepts (code, a path or a credential).', ['name' => $name], category: 'chisel');
        }

        return null;
    }

    /**
     * Allowlisted commands that can actually run here, by name.
     *
     * @return list<string>
     */
    public function runnable(): array
    {
        $names = array_unique([...$this->allowlist(), ...$this->sensitiveAllowlist()]);
        sort($names);

        return array_values(array_filter($names, fn (string $n) => $this->commandRefusal($n) === null));
    }

    /** May this person open Chisel at all? Never while impersonating; admins only unless configured. */
    public function canAccess(?CraftUser $user): bool
    {
        if ($user === null || app(Impersonation::class)->isImpersonating()) {
            return false;
        }

        return $this->isAllowedUser($user);
    }

    public function canViewHistory(?CraftUser $user): bool
    {
        return $this->canAccess($user) && $user?->can(self::PERMISSION_HISTORY) === true;
    }

    public function canRun(?CraftUser $user, string $name): bool
    {
        return $this->canAccess($user)
            && $user?->can(self::PERMISSION_RUN) === true
            && $user->can(self::commandPermission($name));
    }

    /** Re-checked by the queued job: the person who asked must still be allowed, now. */
    public function userMayStillRun(?int $userId, string $name): bool
    {
        if ($userId === null) {
            return false;
        }

        $user = UserElement::find()->id($userId)->status(null)->one();

        return $user instanceof UserElement
            && $user->getStatus() === UserElement::STATUS_ACTIVE
            && $this->isAllowedUser($user)
            && $user->can(self::PERMISSION_RUN)
            && $user->can(self::commandPermission($name));
    }

    public static function commandPermission(string $name): string
    {
        return 'chisel:run:'.strtolower($name);
    }

    private function isAllowedUser(CraftUser $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return self::config('requireAdmin', true) === false && $user->can('accessPlugin-chisel');
    }

    // ---- Elevated session (password re-entry) ----------------------------------------

    /**
     * Seconds a password confirmation lasts: `elevationSeconds` in the config file (60–3600),
     * else 300. Independent of Craft's own elevated sessions, which signing in starts.
     */
    public function elevationSeconds(): int
    {
        $seconds = self::config('elevationSeconds');

        return is_int($seconds) ? max(60, min(3600, $seconds)) : self::FALLBACK_ELEVATION_SECONDS;
    }

    /** Seconds left in this session's elevation (0 = re-enter the password). */
    public function elevationRemaining(): int
    {
        $confirmedAt = Session::get(self::ELEVATION_SESSION_KEY);

        if (! is_int($confirmedAt) && ! is_numeric($confirmedAt)) {
            return 0;
        }

        return max(0, $this->elevationSeconds() - (now()->unix() - (int) $confirmedAt));
    }

    public function isElevated(): bool
    {
        return $this->elevationRemaining() > 0;
    }

    /**
     * Checks the signed-in user's password and starts Chisel's elevated session. Chisel
     * keeps its own (rather than Craft's, which signing in also starts) so running a
     * command always means typing the password again, even straight after signing in.
     * Rate-limited per user.
     */
    public function elevate(CraftUser $user, #[\SensitiveParameter] string $password): bool
    {
        $key = 'chisel-elevate:'.$user->getCraftUserId();

        if (RateLimiter::tooManyAttempts($key, self::ELEVATION_ATTEMPTS_PER_MINUTE)) {
            return false;
        }

        $ok = $password !== '' && app(AuthMethods::class)->authenticate($user, ['password' => $password]);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            Log::warning('Chisel: wrong password when confirming an elevated session.', ['userId' => $user->getCraftUserId()]);

            return false;
        }

        RateLimiter::clear($key);
        Session::put(self::ELEVATION_SESSION_KEY, now()->unix());

        return true;
    }

    public function elevationLockedOut(CraftUser $user): bool
    {
        return RateLimiter::tooManyAttempts('chisel-elevate:'.$user->getCraftUserId(), self::ELEVATION_ATTEMPTS_PER_MINUTE);
    }
}
