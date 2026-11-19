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
        // Internal plumbing that runs serialized code.
        'invoke-serialized-closure', 'schedule:finish', '_complete',
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

    /** Strictest first. */
    private const array ORDER = [self::FORBIDDEN => 0, self::SENSITIVE => 1, self::CONFIRM => 2, self::NORMAL => 3];

    /**
     * Value options offered on every command: identifiers, filters and numbers. Anything else
     * that takes a value (paths, URLs, hosts, PHP or template expressions, class names, file
     * names…) is left off the form unless the config file's `allowedOptions` adds it for one
     * command. Switches (no value) are offered unless forbidden below.
     */
    private const array SAFE_VALUE_OPTIONS = [
        'site', 'status', 'limit', 'offset', 'batch-size', 'element-id', 'uid', 'section', 'type', 'field',
        'owner-id', 'group', 'volume', 'with-fields', 'drafts', 'provisional-drafts', 'revisions',
        'propagate-to', 'set-enabled-for-site', 'queue', 'chunk', 'max', 'max-revisions', 'hours', 'days',
        'since', 'step', 'batch', 'isolated', 'tags', 'id', 'handle',
    ];

    /** Extra value options that are safe for one command. */
    private const array COMMAND_OPTIONS = [
        'down' => ['secret', 'retry', 'refresh'],
    ];

    /**
     * Options never offered or accepted, on any command, whatever the config says: they evaluate
     * PHP or templates (`craft:resave:* --set/--to`, `down --render`), take paths or file names,
     * write files, reach the network, pick classes/seeders to run, or switch environment.
     */
    private const array FORBIDDEN_OPTIONS = [
        'set', 'to', 'to-default', 'render', 'redirect', 'url', 'host', 'port', 'execute', 'env', 'class', 'seeder', 'seed',
        'path', 'realpath', 'schema-path', 'file', 'filename', 'output', 'run-output-file', 'stub', 'template',
        'uriformat', 'composer', 'shell', 'input', 'bootstrap', 'database', 'databases', 'connection', 'driver',
        'key', 'cipher', 'token', 'password', 'username', 'email', 'admin', 'provider', 'force-path',
    ];

    /** Arguments never accepted: code, paths and credentials. A command that requires one can't run. */
    private const array FORBIDDEN_ARGUMENTS = ['code', 'command', 'include', 'path', 'class', 'shell', 'password', 'value', 'config'];

    public static function classify(string $name): string
    {
        $variants = array_unique([strtolower($name), self::normalize($name)]);

        return self::strictest(array_map(self::classifyOne(...), $variants));
    }

    /**
     * A command is as dangerous as its most dangerous name: canonical name and every alias.
     *
     * @param  list<string>  $names
     */
    public static function classifyAll(array $names): string
    {
        return self::strictest(array_map(self::classify(...), $names === [] ? [''] : $names));
    }

    /** `craft:users/setPassword` → `craft:users:set-password`, so alias spellings can't dodge a rule. */
    public static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', str_replace('/', ':', $name)));
    }

    /** @param  list<string>  $risks */
    private static function strictest(array $risks): string
    {
        usort($risks, fn (string $a, string $b) => self::ORDER[$a] <=> self::ORDER[$b]);

        return $risks[0] ?? self::FORBIDDEN;
    }

    private static function classifyOne(string $name): string
    {
        if ($name === '' || in_array($name, self::FORBIDDEN_NAMES, true)) {
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

    /**
     * Whether an option may be offered and accepted.
     *
     * @param  array{name: string, value: string}  $option
     * @param  list<string>  $extra  value options the config file adds for this command
     */
    public static function optionAllowed(string $command, array $option, array $extra = []): bool
    {
        $name = strtolower($option['name']);

        if (in_array($name, self::FORBIDDEN_OPTIONS, true)) {
            return false;
        }

        if ($option['value'] === 'none') {
            return true;
        }

        return in_array($name, self::SAFE_VALUE_OPTIONS, true)
            || in_array($name, self::COMMAND_OPTIONS[strtolower($command)] ?? [], true)
            || in_array($name, array_map('strtolower', $extra), true);
    }

    /** @param  array{name: string}  $argument */
    public static function argumentAllowed(array $argument): bool
    {
        return ! in_array(strtolower($argument['name']), self::FORBIDDEN_ARGUMENTS, true);
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
