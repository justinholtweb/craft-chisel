<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Chisel\Models\Run;

/**
 * `chiselRuns` — read-only run history for monitoring (“did last night's cache clear
 * succeed?”). Present only for schemas with the “View Chisel run history” component.
 *
 * Deliberately thin: commands can't be run, and a run exposes only its command name,
 * status, exit code and timings — never its arguments (they can hold secrets), output,
 * error text, or who ran it.
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 */
class RunsQuery extends Query
{
    public const string COMPONENT = 'chisel.runs:read';

    public const int MAX_LIMIT = 100;

    /** @return array<string, UnnamedFieldDefinitionConfig> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlHelper::canSchema('chisel.runs', 'read')) {
            return [];
        }

        return [
            'chiselRuns' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::runType()))),
                'args' => [
                    'command' => ['name' => 'command', 'type' => Type::string(), 'description' => 'Only runs of this command, e.g. `craft:clear-caches:all`.'],
                    'status' => ['name' => 'status', 'type' => Type::string(), 'description' => 'Only runs with this status: pending, running, succeeded, failed or refused.'],
                    'limit' => ['name' => 'limit', 'type' => Type::int(), 'description' => 'How many, newest first (default 20, at most 100).'],
                ],
                'resolve' => [self::class, 'resolve'],
                'description' => 'Chisel run history, newest first. Read-only; no arguments, output or people.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function resolve(mixed $source, array $args): array
    {
        if (! GqlHelper::canSchema('chisel.runs', 'read')) {
            throw new UserError('This schema can’t read Chisel run history.');
        }

        $status = isset($args['status']) ? (string) $args['status'] : null;
        if ($status !== null && ! in_array($status, Run::STATUSES, true)) {
            throw new UserError('Unknown status. Use one of: '.implode(', ', Run::STATUSES).'.');
        }

        $command = isset($args['command']) ? strtolower((string) $args['command']) : null;
        if ($command !== null && ! preg_match('/^[a-z0-9:_.\-]{1,128}$/D', $command)) {
            throw new UserError('That isn’t a command name.');
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? 20)));

        return Run::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($command !== null, fn ($q) => $q->where('command', $command))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'uid', 'command', 'status', 'exitCode', 'created_at', 'startedAt', 'finishedAt', 'durationMs'])
            ->map(fn (Run $r) => [
                'uid' => $r->uid,
                'command' => $r->command,
                'status' => $r->status,
                'exitCode' => $r->exitCode,
                'queuedAt' => $r->created_at?->toIso8601String(),
                'startedAt' => $r->startedAt?->toIso8601String(),
                'finishedAt' => $r->finishedAt?->toIso8601String(),
                'durationMs' => $r->durationMs,
            ])
            ->values()
            ->all();
    }

    public static function runType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('ChiselRun', fn () => new ObjectType([
            'name' => 'ChiselRun',
            'description' => 'One Chisel command run (metadata only).',
            'fields' => [
                'uid' => ['type' => Type::nonNull(Type::string())],
                'command' => ['type' => Type::nonNull(Type::string()), 'description' => 'The command name, without arguments.'],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending, running, succeeded, failed or refused.'],
                'exitCode' => ['type' => Type::int()],
                'queuedAt' => ['type' => Type::string(), 'description' => 'ISO 8601.'],
                'startedAt' => ['type' => Type::string()],
                'finishedAt' => ['type' => Type::string()],
                'durationMs' => ['type' => Type::int()],
            ],
        ]));
    }
}
