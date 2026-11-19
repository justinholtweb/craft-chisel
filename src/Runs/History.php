<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Runs;

use CraftCms\Cms\Cms;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Support\Facades\Crypt;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Jobs\RunCommand;
use JustinHoltWeb\Chisel\Models\Run;
use JustinHoltWeb\Chisel\Settings;

use function CraftCms\Cms\t;

/**
 * Creating runs (and queueing them), pruning them, and reading them back.
 */
class History
{
    public function __construct(private readonly Chisel $chisel, private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $params  Artisan parameters (stored encrypted until the run ends)
     * @param  list<string>  $display  the masked command line
     */
    public function queue(CraftUser $user, string $command, array $params, array $display, ?string $ip): Run
    {
        $element = $user->asElement();

        $run = Run::query()->create([
            'userId' => $user->getCraftUserId(),
            'username' => mb_substr((string) $element->username, 0, 255),
            'command' => $command,
            'arguments' => $display,
            'parameters' => $params === [] ? null : Crypt::encryptString(json_encode($params, JSON_THROW_ON_ERROR)),
            'status' => Run::PENDING,
            'ip' => Cms::config()->storeUserIps ? $ip : null,
        ]);

        RunCommand::dispatch($run->id, $this->settings->timeout)
            ->onConnection($this->chisel->connection())
            ->onQueue($this->chisel->queue())
            ->afterCommit();

        return $run;
    }

    /** Whether this person already has this command waiting or running (a double submit). */
    public function inFlight(int $userId, string $command): ?Run
    {
        return Run::query()->unfinished()->where('userId', $userId)->where('command', $command)->latest('id')->first();
    }

    /**
     * Deletes runs past retention and closes runs a dead worker left “running”.
     *
     * @return array{int, int} deleted, abandoned
     */
    public function prune(): array
    {
        $deleted = 0;
        if ($this->settings->historyRetentionDays > 0) {
            $deleted = Run::query()->where('created_at', '<', now()->subDays($this->settings->historyRetentionDays))->delete();
        }

        $abandoned = Run::query()
            ->where('status', Run::RUNNING)
            ->where('startedAt', '<', now()->subSeconds($this->settings->timeout + 300))
            ->update(['status' => Run::FAILED, 'error' => t('Abandoned: the worker running it stopped.', category: 'chisel'), 'parameters' => null, 'finishedAt' => now()]);

        return [(int) $deleted, $abandoned];
    }

    /** The most recent run of a command; only `$userId`'s own runs when given. */
    public function lastRun(string $command, ?int $userId = null): ?Run
    {
        return Run::query()->where('command', strtolower($command))
            ->when($userId !== null, fn ($q) => $q->where('userId', $userId))
            ->latest('id')->first();
    }
}
