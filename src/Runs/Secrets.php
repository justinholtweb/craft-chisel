<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Runs;

/**
 * The install's own secret values, for {@see OutputSanitizer}: every environment variable
 * and config value whose name looks secret (APP_KEY, DB_PASSWORD, *_TOKEN, *_SECRET, …),
 * the app key and previous keys, and the password part of any URL-shaped env value.
 */
final class Secrets
{
    private const string SECRET_NAME = '/(^|[_.\-])(key|keys|secret|password|passwd|pass|pwd|token|credentials?|salt|dsn|auth|private)([_.\-]|$)/i';

    /** @return list<string> */
    public static function collect(): array
    {
        $values = [];

        $env = array_merge(getenv() ?: [], $_ENV, array_filter($_SERVER, 'is_string'));
        foreach ($env as $name => $value) {
            if (! is_string($value) || ! is_string($name)) {
                continue;
            }
            if (self::isSecretName($name)) {
                $values[] = $value;
            }
            // postgres://user:pass@host, redis://:pass@host …
            if (preg_match('/^[a-z][a-z0-9+.\-]*:\/\/[^\s:\/@]*:([^\s@\/]+)@/i', $value, $m)) {
                $values[] = rawurldecode($m[1]);
            }
        }

        $config = config()->all();
        array_walk_recursive($config, function (mixed $value, int|string $key) use (&$values): void {
            if (is_string($value) && is_string($key) && self::isSecretName($key)) {
                $values[] = $value;
            }
        });

        foreach ([config('app.key'), ...(array) config('app.previous_keys', [])] as $key) {
            if (is_string($key) && $key !== '') {
                $values[] = $key;
                if (str_starts_with($key, 'base64:')) {
                    $values[] = substr($key, 7);
                }
            }
        }

        return array_values(array_unique(array_filter($values, fn (string $v) => mb_strlen(trim($v)) >= 4 && ! self::isBoring($v))));
    }

    public static function isSecretName(string $name): bool
    {
        // camelCase → words, so `apiKey` and `clientSecret` count.
        $name = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);

        return (bool) preg_match(self::SECRET_NAME, $name);
    }

    /** Values under secret-looking names that aren't secrets and would shred ordinary output. */
    private static function isBoring(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['null', 'true', 'false', 'none', 'file', 'array', 'database', 'redis', 'local', 'public', 'private', 'default', 'session', 'cookie', 'token', 'bearer', 'sha256', 'aes-256-cbc'], true)
            || (bool) preg_match('/^\d{1,6}$/', $value);
    }
}
