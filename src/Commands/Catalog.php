<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Chisel\Jobs\RefreshCatalog;
use LogicException;

/**
 * Every Artisan command's signature, captured in a console process and stored.
 *
 * Craft registers its own `craft:*` commands (and many packages theirs) only when PHP runs
 * from the command line, so a control-panel request can't see them. A queue worker can: the
 * catalog is refreshed by `craft:chisel:refresh`, by a queued {@see RefreshCatalog},
 * and before every run, and the control panel reads the stored copy.
 */
class Catalog
{
    public const string TABLE = 'chisel_commands';

    /** @var array<string, CommandSpec>|null */
    private ?array $specs = null;

    public function __construct(private readonly Kernel $artisan) {}

    /** Re-reads every command's signature. Console processes only. Returns how many were stored. */
    public function refresh(): int
    {
        if (! app()->runningInConsole()) {
            throw new LogicException('The Chisel command catalog can only be refreshed from a console process (a queue worker or `craft:chisel:refresh`).');
        }

        $now = now();
        $rows = [];
        foreach ($this->artisan->all() as $name => $command) {
            $name = strtolower((string) $name);
            if (! CommandPolicy::isValidName($name) || $command->isHidden() || isset($rows[$name])) {
                continue;
            }
            $spec = CommandSpec::fromCommand($command);
            $rows[$name] = [
                'name' => $name,
                'description' => mb_substr($spec->description, 0, 1000),
                'definition' => json_encode($spec->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            DB::table(self::TABLE)->delete();
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                DB::table(self::TABLE)->insert($chunk);
            }
        });
        $this->specs = null;

        return count($rows);
    }

    /** @return array<string, CommandSpec> by name, sorted */
    public function all(): array
    {
        if ($this->specs === null) {
            $this->specs = [];
            foreach (DB::table(self::TABLE)->orderBy('name')->get(['name', 'definition']) as $row) {
                $data = json_decode((string) $row->definition, true);
                if (is_array($data)) {
                    $this->specs[(string) $row->name] = CommandSpec::fromArray($data);
                }
            }
        }

        return $this->specs;
    }

    public function get(string $name): ?CommandSpec
    {
        return $this->all()[strtolower($name)] ?? null;
    }

    public function refreshedAt(): ?CarbonImmutable
    {
        $at = DB::table(self::TABLE)->max('updated_at');

        return $at ? CarbonImmutable::parse((string) $at) : null;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }
}
