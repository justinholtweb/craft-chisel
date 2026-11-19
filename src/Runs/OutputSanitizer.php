<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Runs;

/**
 * Masks secrets in command output before it is stored or shown. Best effort by design:
 * it knows the install's own secrets (passed in — see {@see Secrets}) and recognises
 * common secret shapes, but a command that prints a secret it computed itself can't be
 * caught, which is one reason such commands are forbidden or config-file-only.
 *
 * Pure (no container) and unit-tested.
 */
final class OutputSanitizer
{
    public const string MASK = '••••••';

    /** @var list<string> longest first, so a secret containing another is masked whole */
    private array $secrets;

    /** @param  list<string>  $secrets  exact values to mask wherever they appear */
    public function __construct(array $secrets = [])
    {
        $secrets = array_values(array_unique(array_filter($secrets, fn (string $s) => mb_strlen($s) >= 4)));
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $this->secrets = $secrets;
    }

    public function clean(string $output): string
    {
        // Terminal escapes (colours, cursor movement, OSC hyperlinks) and stray control characters.
        $output = (string) preg_replace('/\x1B\][^\x07\x1B]*(\x07|\x1B\\\\)|\x1B\[[0-9;?]*[ -\/]*[@-~]|\x1B[@-Z\\\\-_]/', '', $output);
        $output = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace("\r\n", "\n", $output));

        foreach ($this->secrets as $secret) {
            $output = mb_strlen($secret) >= 8
                ? str_replace($secret, self::MASK, $output)
                // Short values (a 4–7 character password) only as whole words, so they don't shred ordinary text.
                : (string) preg_replace('/(?<![\w])'.preg_quote($secret, '/').'(?![\w])/u', self::MASK, $output);
        }

        $patterns = [
            // Laravel keys: base64:… and 32-char hex/alnum after APP_KEY-like names.
            '/base64:[A-Za-z0-9+\/]{16,}={0,2}/' => 'base64:'.self::MASK,
            // KEY=value, "secret": "value", password: value — names that look secret, any separator.
            '/((?<![\w.\-])(?:[\w.\-]*[_.\-])?(?:KEY|SECRET|PASSWORD|PASSWD|PASS|PWD|TOKEN|CREDENTIALS?|SALT|DSN)(?:[_.\-][\w.\-]*)?["\']?\s*(?:=|:|=>|\.{2,}|\s{2,})\s*["\']?)([^\s"\',]{4,})/i' => '$1'.self::MASK,
            // user:password@ in URLs and DSNs.
            '/\b([a-z][a-z0-9+.\-]*:\/\/[^\s:\/@]*):[^\s@\/]+@/i' => '$1:'.self::MASK.'@',
            // Bearer / Basic authorization values.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/' => '$1 '.self::MASK,
            // Well-known token formats (Stripe, GitHub, Slack, AWS access key ids, JWTs).
            '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{8,}\b/' => self::MASK,
            '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/' => self::MASK,
            '/\bxox[abposr]-[A-Za-z0-9\-]{10,}\b/' => self::MASK,
            '/\bAKIA[0-9A-Z]{16}\b/' => self::MASK,
            '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\b/' => self::MASK,
            // PEM private keys.
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => '-----BEGIN PRIVATE KEY----- '.self::MASK.' -----END PRIVATE KEY-----',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $output = (string) preg_replace($pattern, $replacement, $output);
        }

        return $output;
    }
}
