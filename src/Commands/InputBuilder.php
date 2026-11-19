<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Commands;

use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

/**
 * Turns submitted form values into Artisan parameters, using only the inputs the command
 * itself defines. Nothing is ever parsed as a command line: each value is passed as one
 * array element to `Artisan::call()`, so a value like `--force` or `; rm -rf` is just text.
 *
 * Pure (no container) and unit-tested. Errors are keyed like the form fields
 * (`args.name` / `opts.name`) and are human sentences without the value.
 */
final class InputBuilder
{
    public const int MAX_VALUE_LENGTH = 1000;

    public const int MAX_ITEMS = 100;

    /** @var array<string, string> */
    public array $errors = [];

    private readonly \Closure $translate;

    private readonly CommandSpec $spec;

    /** The command requires an input Chisel never accepts. */
    private readonly bool $unusable;

    /**
     * @param  list<string>  $siteHandles  real site handles, for a `--site` option
     * @param  (callable(string, array<string, string|int>): string)|null  $translate  the CP translator; plain substitution by default
     * @param  list<string>  $extraOptions  value options the config file allows for this command
     */
    public function __construct(CommandSpec $spec, private readonly array $siteHandles, ?callable $translate = null, array $extraOptions = [])
    {
        // The option/argument policy is applied here too, whatever spec the caller passed.
        $allowed = $spec->withPolicy($extraOptions);
        $this->unusable = $allowed === null;
        $this->spec = $allowed ?? new CommandSpec($spec->name, $spec->description, [], [], $spec->aliases);
        $this->translate = \Closure::fromCallable($translate ?? fn (string $m, array $p): string => strtr($m, array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($p)), array_map('strval', $p))));
    }

    /** @param  array<string, string|int>  $params */
    private function error(string $key, string $message, array $params = []): void
    {
        $this->errors[$key] = ($this->translate)($message, $params);
    }

    /**
     * @param  array<array-key, mixed>  $args  `args[name]` from the form
     * @param  array<array-key, mixed>  $opts  `opts[name]` from the form
     * @return array{params: array<string, string|bool|list<string>>, display: list<string>}|null null when invalid ({@see $errors})
     */
    public function build(array $args, array $opts): ?array
    {
        $this->errors = [];
        if ($this->unusable) {
            $this->error('form', 'This command needs an input Chisel never accepts (code, a path or a credential).');

            return null;
        }
        $params = [];
        $display = [];

        foreach ($this->spec->arguments as $a) {
            $key = 'args.'.$a['name'];
            $raw = $args[$a['name']] ?? null;
            $values = $this->values($raw, $a['array'], $key);
            if ($values === null) {
                continue;
            }
            if ($values === []) {
                if ($a['required']) {
                    $this->error($key, '“{name}” is required.', ['name' => $a['name']]);
                }

                continue;
            }
            $params[$a['name']] = $a['array'] ? $values : $values[0];
            foreach ($values as $v) {
                $display[] = self::isSensitiveName($a['name']) ? '••••' : self::quote($v);
            }
        }

        foreach ($this->spec->options as $o) {
            $key = 'opts.'.$o['name'];
            $raw = $opts[$o['name']] ?? null;

            if ($o['value'] === 'none') {
                if (in_array($raw, ['1', 'on', 'true', 1, true], true)) {
                    $params['--'.$o['name']] = true;
                    $display[] = '--'.$o['name'];
                }

                continue;
            }

            $values = $this->values($raw, $o['array'], $key);
            if ($values === null || $values === []) {
                continue;
            }

            if ($o['name'] === 'site') {
                foreach ($values as $v) {
                    if (! in_array($v, $this->siteHandles, true)) {
                        $this->error($key, 'Choose one of this install’s sites.');

                        continue 2;
                    }
                }
            }

            $params['--'.$o['name']] = $o['array'] ? $values : $values[0];
            foreach ($values as $v) {
                $display[] = '--'.$o['name'].'='.(self::isSensitiveName($o['name']) ? '••••' : self::quote($v));
            }
        }

        if ($this->errors !== []) {
            return null;
        }

        // Last line of defence: Symfony binds the exact parameters against the definition.
        try {
            $input = new ArrayInput($params, $this->spec->definition());
            $input->validate();
        } catch (Throwable $e) {
            $this->errors['form'] = $e->getMessage();

            return null;
        }

        return ['params' => $params, 'display' => $display];
    }

    /**
     * One value, or one per line for array inputs. Null (with an error) when a value is
     * malformed: too long, too many, or containing control characters.
     *
     * @return list<string>|null
     */
    private function values(mixed $raw, bool $array, string $key): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (is_array($raw)) {
            if (! $array) {
                $this->error($key, 'Give one value.');

                return null;
            }
            $items = $raw;
        } elseif (is_scalar($raw)) {
            $items = $array ? preg_split('/\r\n|\r|\n/', (string) $raw) : [(string) $raw];
        } else {
            $this->error($key, 'That value isn’t allowed.');

            return null;
        }

        $values = [];
        foreach ($items ?: [] as $item) {
            if (! is_scalar($item)) {
                $this->error($key, 'That value isn’t allowed.');

                return null;
            }
            $v = trim((string) $item);
            if ($v === '') {
                continue;
            }
            if (mb_strlen($v) > self::MAX_VALUE_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                $this->error($key, 'Values must be one line of at most {max} characters.', ['max' => self::MAX_VALUE_LENGTH]);

                return null;
            }
            $values[] = $v;
        }

        if (count($values) > self::MAX_ITEMS) {
            $this->error($key, 'At most {max} values.', ['max' => self::MAX_ITEMS]);

            return null;
        }

        return $values;
    }

    /**
     * Re-checks stored parameters against a (possibly newer) signature before they run:
     * only names the command defines, and Symfony must accept them.
     *
     * @param  array<array-key, mixed>  $params
     * @param  list<string>  $extraOptions
     */
    public static function paramsFit(CommandSpec $spec, array $params, array $extraOptions = []): bool
    {
        $spec = $spec->withPolicy($extraOptions);
        if ($spec === null) {
            return false;
        }

        $isArray = [];
        foreach ($spec->arguments as $a) {
            $isArray[$a['name']] = $a['array'];
        }
        foreach ($spec->options as $o) {
            $isArray['--'.$o['name']] = $o['array'] && $o['value'] !== 'none';
        }

        foreach ($params as $key => $value) {
            if (! array_key_exists($key, $isArray)) {
                return false;
            }
            $ok = $isArray[$key]
                ? is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value
                : is_string($value) || $value === true;
            if (! $ok) {
                return false;
            }
        }

        try {
            new ArrayInput($params, $spec->definition())->validate();
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** Arguments/options whose values are masked in the run history (`--password`, `token`, `api-key`…). */
    public static function isSensitiveName(string $name): bool
    {
        return (bool) preg_match('/(^|[_\-])(pass|password|passwd|pw|pwd|secret|token|tok|key|apikey|auth|credential|credentials|private|salt|sk|dsn)([_\-]|$)/i', $name);
    }

    private static function quote(string $v): string
    {
        return preg_match('/^[\w.\/:@,+=-]+$/u', $v) ? $v : '"'.addcslashes($v, '"\\').'"';
    }
}
