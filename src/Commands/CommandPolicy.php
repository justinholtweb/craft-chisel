<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Commands;

/**
 * How dangerous a command is, by name. Pure (no container), so it is unit-tested directly.
 *
 * - **forbidden** — never runs from a browser, whatever the allowlist or config says: shells,
 *   REPLs and daemons (they never return), commands that destroy the database or keys, and
 *   commands that print a credential (impersonation/reset links, tokens, OAuth secrets) or
 *   write PHP code.
 * - **sensitive** — reveals configuration or environment values, or manages accounts, plugins
 *   or code. Allowed only when listed in the config file's `sensitiveCommands`.
 * - **confirm** — changes or removes data in bulk, or takes the site down; needs the command's
 *   name typed in before it runs.
 * - **normal** — everything else (still needs to be allowlisted).
 */
final class CommandPolicy
{
    public const string FORBIDDEN = 'forbidden';

    public const string SENSITIVE = 'sensitive';

    public const string CONFIRM = 'confirm';

    public const string NORMAL = 'normal';

    /** Exact names that never run. */
    private const array FORBIDDEN_NAMES = [
        // Shells, REPLs, servers and workers: interactive or never return.
        'tinker', 'db', 'serve', 'pail', 'dev', 'completion', 'test',
        'queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'schedule:test',
        'pulse:work', 'pulse:check', 'inertia:start-ssr', 'craft:mcp:serve',
        'reverb:start', 'horizon', 'octane:start', 'octane:frankenphp', 'octane:roadrunner', 'octane:swoole',
        // Irreversible damage to the database, keys or install.
        'db:wipe', 'migrate:fresh', 'migrate:reset', 'migrate:refresh',
        'key:generate', 'env:encrypt', 'env:decrypt',
        'craft:db:drop-all-tables', 'craft:db:restore', 'craft:db:drop-table-prefix',
        'craft:install', 'craft:setup', 'craft:setup:db-creds', 'craft:setup:publish', 'craft:setup:welcome',
        // Print a credential or a link that signs someone in.
        'craft:users:impersonate', 'craft:users:password-reset-url', 'craft:users:activation-url',
        'craft:users:set-password', 'craft:graphql:create-token',
        'passport:keys', 'passport:client', 'passport:install',
        // Install packages or run Composer.
        'craft:update', 'craft:update:composer-install',
    ];

    /** Name prefixes that never run: code generation and installers. */
    private const array FORBIDDEN_PREFIXES = ['make:', 'install:', 'octane:', 'horizon:'];

    /** Reveal configuration/env values or manage accounts, plugins and code: config file only. */
    private const array SENSITIVE_NAMES = [
        'about', 'env', 'config:show', 'config:cache', 'config:publish', 'model:show',
        'db:show', 'db:table', 'db:monitor', 'db:seed',
        'craft:env:show', 'craft:env:set', 'craft:env:remove',
        'craft:project-config:get', 'craft:project-config:set', 'craft:project-config:remove',
        'craft:graphql:print-schema', 'craft:graphql:dump-schema', 'craft:graphql:list-schemas',
        'craft:users:create', 'craft:users:delete', 'craft:users:remove-2fa', 'craft:users:unlock',
        'craft:users:list-admins', 'craft:users:logout-all',
        'craft:plugin:install', 'craft:plugin:uninstall', 'craft:plugin:enable', 'craft:plugin:disable',
        'craft:db:backup', 'craft:db:convert-charset', 'craft:mailer:test',
        'vendor:publish', 'stub:publish', 'lang:publish', 'storage:link', 'storage:unlink', 'schema:dump',
        'cashier:webhook', 'passport:hash', 'passport:purge',
    ];

    /** Exact names that need the typed confirmation. */
    private const array CONFIRM_NAMES = [
        'down', 'up', 'migrate', 'craft:up', 'craft:migrate:all', 'craft:project-config:apply',
        'craft:project-config:rebuild', 'craft:project-config:write', 'craft:project-config:touch',
        'craft:resave:all', 'craft:resave:entries', 'craft:resave:assets', 'craft:resave:users', 'craft:resave:addresses',
        'scout:import', 'scout:queue-import', 'queue:clear', 'auth:clear-resets', 'queue:retry', 'queue:retry-batch', 'queue:pause', 'queue:resume',
        'craft:index-assets:all', 'craft:index-assets:one', 'craft:index-assets:cleanup',
        'craft:fields:auto-merge', 'craft:entry-types:merge', 'craft:sections:create',
        'craft:eloquent-elements:sync', 'ckeditor:convert:matrix', 'ckeditor:convert:redactor',
    ];

    /** Words in a name that mean it removes or rewrites data in bulk. */
    private const string CONFIRM_WORDS = '/(^|[:\-])(delete|drop|flush|purge|prune|remove|reset|rollback|wipe|restore|uninstall|disable|repair|merge|cleanup|logout|interrupt|cancel)([:\-]|$)/';

    public static function classify(string $name): string
    {
        $name = strtolower($name);

        if (in_array($name, self::FORBIDDEN_NAMES, true)) {
            return self::FORBIDDEN;
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return self::FORBIDDEN;
            }
        }

        if (in_array($name, self::SENSITIVE_NAMES, true)) {
            return self::SENSITIVE;
        }

        if (in_array($name, self::CONFIRM_NAMES, true) || preg_match(self::CONFIRM_WORDS, $name)) {
            return self::CONFIRM;
        }

        return self::NORMAL;
    }

    /** Command names Chisel will consider at all: lowercase words joined by `:`, `-`, `_` or `.`. */
    public static function isValidName(string $name): bool
    {
        return strlen($name) <= 128 && (bool) preg_match('/^[a-z0-9][a-z0-9_\-.]*(:[a-z0-9][a-z0-9_\-.]*)*$/D', $name);
    }

    /**
     * A sensible starting allowlist: read-only reports and cache clearing.
     *
     * @return list<string>
     */
    public static function suggested(): array
    {
        return [
            'craft:clear-caches:all', 'craft:clear-caches:data', 'craft:clear-caches:compiled-templates',
            'craft:invalidate-tags:all', 'craft:project-config:diff', 'craft:project-config:check',
            'craft:update:info', 'migrate:status', 'queue:failed', 'queue:monitor',
            'schedule:list', 'route:list', 'event:list', 'optimize:clear', 'view:clear',
        ];
    }
}
