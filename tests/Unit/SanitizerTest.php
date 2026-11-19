<?php

declare(strict_types=1);

use JustinHoltWeb\Chisel\Runs\OutputSanitizer;
use JustinHoltWeb\Chisel\Runs\Secrets;
use JustinHoltWeb\Chisel\Runs\StreamingOutput;

it('masks the install’s own secrets wherever they appear', function () {
    $s = new OutputSanitizer(['s3cr3t-value-long', 'abcd']);
    expect($s->clean('x s3cr3t-value-long y'))->toBe('x '.OutputSanitizer::MASK.' y')
        ->and($s->clean('abcd abcdef'))->toBe(OutputSanitizer::MASK.' abcdef');
});

it('masks secret-shaped output', function (string $in, string $out) {
    expect(new OutputSanitizer([])->clean($in))->toBe($out);
})->with([
    ['APP_KEY=base64:0iNaaPI8ezMklSQPWToycEOhH6p8aFFguvFQKiPbyjY=', 'APP_KEY=••••••'],
    ['DB_PASSWORD=hunter2', 'DB_PASSWORD=••••••'],
    ['"api_key": "zzzz9999"', '"api_key": "••••••"'],
    ['Application Key ........ abc123def', 'Application Key ........ ••••••'],
    ['redis://user:pa55word@host:6379', 'redis://user:••••••@host:6379'],
    ['Authorization: Bearer abcdef0123456789', 'Authorization: Bearer ••••••'],
    ['sk_live_0123456789abcdef', '••••••'],
    ['ghp_0123456789abcdefghij0123', '••••••'],
    ["a\e[31mred\e[0m b", 'ared b'],
]);

it('leaves ordinary output alone', function () {
    $text = "monkey: banana\nauthor: Justin\nCompiled views cleared successfully.\nTests passed: 12\n";
    expect(new OutputSanitizer([])->clean($text))->toBe($text);
});

it('recognises secret names', function () {
    expect(Secrets::isSecretName('APP_KEY'))->toBeTrue()
        ->and(Secrets::isSecretName('clientSecret'))->toBeTrue()
        ->and(Secrets::isSecretName('DB_PASSWORD'))->toBeTrue()
        ->and(Secrets::isSecretName('APP_NAME'))->toBeFalse()
        ->and(Secrets::isSecretName('monkey'))->toBeFalse();
});

it('streams only whole lines, sanitised, and caps output at a line boundary', function () {
    $flushed = [];
    $out = new StreamingOutput(new OutputSanitizer(['topsecretvalue']), function (string $t, bool $truncated) use (&$flushed) {
        $flushed[] = [$t, $truncated];
    }, 64, 0.0);

    $out->write('first line topsecretvalue', true);
    $out->write('partial topsec', false);
    expect(end($flushed)[0])->toBe("first line ••••••\n");

    $out->write(str_repeat('x', 100), true);
    expect($out->truncated())->toBeTrue()
        ->and($out->text())->toBe("first line ••••••\n");
});
