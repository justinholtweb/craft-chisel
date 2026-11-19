<?php

declare(strict_types=1);

use JustinHoltWeb\Chisel\Commands\CommandPolicy;
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
