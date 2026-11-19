<?php

declare(strict_types=1);

use JustinHoltWeb\Chisel\Commands\CommandSpec;
use JustinHoltWeb\Chisel\Commands\InputBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

function probeSpec(): CommandSpec
{
    return CommandSpec::fromDefinition('chisel:probe', 'Probe', new InputDefinition([
        new InputArgument('word', InputArgument::REQUIRED, 'A word'),
        new InputArgument('extra', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'More'),
        new InputOption('site', null, InputOption::VALUE_REQUIRED, 'Site'),
        new InputOption('times', null, InputOption::VALUE_REQUIRED, 'Times', '1'),
        new InputOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tags'),
        new InputOption('loud', null, InputOption::VALUE_NONE, 'Shout'),
        new InputOption('password', null, InputOption::VALUE_REQUIRED, 'A secret'),
        new InputOption('no-interaction', 'n', InputOption::VALUE_NONE, 'global'),
        new InputOption('env', null, InputOption::VALUE_REQUIRED, 'global'),
    ]));
}

it('captures a signature without the global options', function () {
    $spec = probeSpec();
    expect(array_column($spec->options, 'name'))->toBe(['site', 'times', 'tag', 'loud', 'password'])
        ->and($spec->arguments[0])->toMatchArray(['name' => 'word', 'required' => true, 'array' => false])
        ->and($spec->option('times')['default'])->toBe('1')
        ->and(CommandSpec::fromArray(json_decode(json_encode($spec->toArray()), true)))->toEqual($spec);
});

it('builds Artisan parameters from form input', function () {
    $b = new InputBuilder(probeSpec(), ['default', 'fr']);
    $built = $b->build(['word' => 'hello; rm -rf /', 'extra' => "a\n\nb"], ['site' => 'fr', 'tag' => "x\ny", 'loud' => '1', 'times' => '']);

    expect($b->errors)->toBe([])
        ->and($built['params'])->toBe(['word' => 'hello; rm -rf /', 'extra' => ['a', 'b'], '--site' => 'fr', '--tag' => ['x', 'y'], '--loud' => true])
        ->and($built['display'])->toBe(['"hello; rm -rf /"', 'a', 'b', '--site=fr', '--tag=x', '--tag=y', '--loud']);
});

it('ignores inputs the command does not define, including global options', function () {
    $b = new InputBuilder(probeSpec(), ['default']);
    $built = $b->build(['word' => 'x', '--env' => 'production'], ['env' => 'production', 'no-interaction' => '1', 'evil' => '1']);

    expect($built['params'])->toBe(['word' => 'x']);
});

it('refuses unknown sites, missing arguments and malformed values', function () {
    $b = new InputBuilder(probeSpec(), ['default']);
    expect($b->build([], ['site' => 'nope']))->toBeNull()
        ->and($b->errors)->toHaveKeys(['args.word', 'opts.site']);

    expect($b->build(['word' => "two\x00lines"], []))->toBeNull()
        ->and($b->errors)->toHaveKey('args.word');

    expect($b->build(['word' => ['a', 'b']], []))->toBeNull()
        ->and($b->build(['word' => str_repeat('a', 1001)], []))->toBeNull()
        ->and($b->build(['word' => 'x'], ['tag' => implode("\n", range(1, 101))]))->toBeNull();
});

it('masks sensitive values in the display line only', function () {
    $built = new InputBuilder(probeSpec(), [])->build(['word' => 'x'], ['password' => 'hunter2']);

    expect($built['params']['--password'])->toBe('hunter2')
        ->and($built['display'])->toBe(['x', '--password=••••']);
});

it('rechecks stored parameters against the current signature', function () {
    $spec = probeSpec();
    expect(InputBuilder::paramsFit($spec, ['word' => 'x', '--loud' => true]))->toBeTrue()
        ->and(InputBuilder::paramsFit($spec, ['word' => 'x', '--env' => 'production']))->toBeFalse()
        ->and(InputBuilder::paramsFit($spec, ['--loud' => true]))->toBeFalse()
        ->and(InputBuilder::paramsFit($spec, ['word' => ['a']]))->toBeFalse();
});

it('reads a real command', function () {
    $command = new Command('demo:thing');
    $command->setDescription('Does a thing')->addArgument('name', InputArgument::OPTIONAL, 'Name', 'bob');
    expect(CommandSpec::fromCommand($command)->toArray())->toMatchArray(['name' => 'demo:thing', 'description' => 'Does a thing'])
        ->and(CommandSpec::fromCommand($command)->arguments[0]['default'])->toBe('bob');
});
