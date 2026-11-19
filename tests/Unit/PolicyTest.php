<?php

declare(strict_types=1);

use JustinHoltWeb\Chisel\Commands\CommandPolicy;
use JustinHoltWeb\Chisel\Http\Controllers\RunsController;
use JustinHoltWeb\Chisel\Settings;

it('forbids shells, workers, destroyers and credential printers', function (string $name) {
    expect(CommandPolicy::classify($name))->toBe(CommandPolicy::FORBIDDEN);
})->with([
    'tinker', 'db', 'serve', 'queue:work', 'queue:listen', 'schedule:work', 'db:wipe', 'migrate:fresh',
    'migrate:reset', 'migrate:refresh', 'key:generate', 'env:decrypt', 'craft:db:drop-all-tables',
    'craft:db:restore', 'craft:users:impersonate', 'craft:users:password-reset-url', 'craft:graphql:create-token',
    'make:controller', 'make:model', 'install:api', 'craft:mcp:serve', 'craft:update', 'TINKER',
]);

it('marks configuration-revealing and account commands sensitive', function (string $name) {
    expect(CommandPolicy::classify($name))->toBe(CommandPolicy::SENSITIVE);
})->with(['about', 'env', 'config:show', 'craft:env:show', 'db:show', 'craft:users:create', 'craft:plugin:uninstall', 'vendor:publish']);

it('asks for confirmation before bulk or site-wide changes', function (string $name) {
    expect(CommandPolicy::classify($name))->toBe(CommandPolicy::CONFIRM);
})->with(['down', 'migrate', 'migrate:rollback', 'craft:up', 'craft:resave:entries', 'queue:flush', 'queue:clear', 'scout:flush', 'craft:elements:delete', 'cache:prune-stale-tags', 'craft:project-config:apply']);

it('leaves everyday commands normal', function (string $name) {
    expect(CommandPolicy::classify($name))->toBe(CommandPolicy::NORMAL);
})->with(['inspire', 'cache:clear', 'craft:clear-caches:all', 'migrate:status', 'route:list', 'queue:restart', 'craft:invalidate-tags:all']);

it('never suggests anything that is not normal', function () {
    foreach (CommandPolicy::suggested() as $name) {
        expect(CommandPolicy::classify($name))->toBe(CommandPolicy::NORMAL, $name);
    }
});

it('accepts only plain command names', function () {
    expect(CommandPolicy::isValidName('craft:clear-caches:all'))->toBeTrue()
        ->and(CommandPolicy::isValidName('chisel:probe'))->toBeTrue()
        ->and(CommandPolicy::isValidName('cache:clear; rm -rf /'))->toBeFalse()
        ->and(CommandPolicy::isValidName('--help'))->toBeFalse()
        ->and(CommandPolicy::isValidName('Cache:Clear'))->toBeFalse()
        ->and(CommandPolicy::isValidName(''))->toBeFalse()
        ->and(CommandPolicy::isValidName('a::b'))->toBeFalse()
        ->and(CommandPolicy::isValidName(str_repeat('a', 129)))->toBeFalse();
});

it('normalises the allowlist', function () {
    expect(Settings::commandList("Cache:Clear\n inspire ,inspire\nbad name;x"))->toBe(['cache:clear', 'inspire', 'bad', 'name;x'] === [] ? [] : ['cache:clear', 'inspire', 'bad'])
        ->and(Settings::commandList(['migrate:status', 42, null, 'x y']))->toBe(['migrate:status'])
        ->and(Settings::commandList(null))->toBe([]);
});

it('neutralises spreadsheet formulas in the CSV export', function () {
    expect(RunsController::csvCell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(RunsController::csvCell('-v'))->toBe("'-v")
        ->and(RunsController::csvCell('inspire'))->toBe('inspire')
        ->and(RunsController::csvCell(null))->toBe('');
});

it('classifies a command by its strictest name or alias', function () {
    expect(CommandPolicy::classifyAll(['craft:db:restore', 'craft:restore', 'craft:restore:db']))->toBe(CommandPolicy::FORBIDDEN)
        ->and(CommandPolicy::classifyAll(['harmless:thing', 'craft:db:restore']))->toBe(CommandPolicy::FORBIDDEN)
        ->and(CommandPolicy::classifyAll(['craft:project-config:set', 'craft:pc:set']))->toBe(CommandPolicy::SENSITIVE)
        ->and(CommandPolicy::classifyAll(['inspire']))->toBe(CommandPolicy::NORMAL)
        ->and(CommandPolicy::classifyAll([]))->toBe(CommandPolicy::FORBIDDEN);
});

it('sees through slash and camelCase alias spellings', function () {
    expect(CommandPolicy::classify('craft:users/setPassword'))->toBe(CommandPolicy::FORBIDDEN)
        ->and(CommandPolicy::classify('craft:users/impersonate'))->toBe(CommandPolicy::FORBIDDEN)
        ->and(CommandPolicy::classify('craft:project-config/set'))->toBe(CommandPolicy::SENSITIVE)
        ->and(CommandPolicy::normalize('craft:users/setPassword'))->toBe('craft:users:set-password');
});

it('forbids internal plumbing that runs serialized code', function () {
    expect(CommandPolicy::classify('invoke-serialized-closure'))->toBe(CommandPolicy::FORBIDDEN)
        ->and(CommandPolicy::classify('schedule:finish'))->toBe(CommandPolicy::FORBIDDEN);
});

it('allowlists value options; forbids code, path and network options everywhere', function () {
    $v = fn (string $n) => ['name' => $n, 'value' => 'required'];
    expect(CommandPolicy::optionAllowed('craft:resave:entries', $v('section')))->toBeTrue()
        ->and(CommandPolicy::optionAllowed('craft:resave:entries', $v('set')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('craft:resave:entries', $v('to')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('craft:resave:entries', $v('to'), ['to']))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('down', $v('render')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('down', $v('secret')))->toBeTrue()
        ->and(CommandPolicy::optionAllowed('migrate', $v('path')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('migrate', ['name' => 'seed', 'value' => 'none']))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('migrate', ['name' => 'pretend', 'value' => 'none']))->toBeTrue()
        ->and(CommandPolicy::optionAllowed('cashier:webhook', $v('url')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('x', $v('whatever')))->toBeFalse()
        ->and(CommandPolicy::optionAllowed('x', $v('whatever'), ['whatever']))->toBeTrue()
        ->and(CommandPolicy::argumentAllowed(['name' => 'path']))->toBeFalse()
        ->and(CommandPolicy::argumentAllowed(['name' => 'handle']))->toBeTrue();
});
