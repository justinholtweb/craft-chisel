<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Templates;

use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Models\Run;

use function CraftCms\Cms\currentUser;

/**
 * The `chisel` template global (Twig `chisel.lastRun('…')`, Blade `$chisel->lastRun('…')`).
 * Read-only, and empty for anyone who couldn't open Chisel → History: there is nothing
 * here for site visitors. Meant for an admin-only status line, e.g. on a dashboard page.
 */
class ChiselVariable
{
    public function __construct(private readonly Chisel $chisel) {}

    /**
     * The most recent run of a command, as metadata (no arguments or output), or null.
     *
     * @return array{command: string, status: string, exitCode: ?int, queuedAt: ?string, finishedAt: ?string, durationMs: ?int}|null
     */
    public function lastRun(string $command): ?array
    {
        if (! $this->chisel->canViewHistory(currentUser())) {
            return null;
        }

        $run = Run::query()->where('command', strtolower($command))->latest('id')->first();

        return $run === null ? null : [
            'command' => $run->command,
            'status' => $run->status,
            'exitCode' => $run->exitCode,
            'queuedAt' => $run->created_at?->toIso8601String(),
            'finishedAt' => $run->finishedAt?->toIso8601String(),
            'durationMs' => $run->durationMs,
        ];
    }

    /** Whether the signed-in person can see Chisel's history (so templates can skip the markup). */
    public function canView(): bool
    {
        return $this->chisel->canViewHistory(currentUser());
    }
}
