<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Commands\Catalog;
use JustinHoltWeb\Chisel\Commands\InputBuilder;
use JustinHoltWeb\Chisel\Models\Run;
use JustinHoltWeb\Chisel\Runs\OutputSanitizer;
use JustinHoltWeb\Chisel\Runs\Secrets;
use JustinHoltWeb\Chisel\Runs\StreamingOutput;
use JustinHoltWeb\Chisel\Settings;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Runs one queued command in the queue worker (a console process, so every command is
 * registered). The payload is only the run's id: the parameters live encrypted on the run
 * and are wiped when it ends. Everything is checked again here — the environment, the
 * allowlist, the person who asked — because time passes between asking and running.
 *
 * Never retried: running a command twice is not a safe default.
 */
class RunCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $runId, int $timeout = 600)
    {
        $this->timeout = $timeout;
    }

    public function handle(Chisel $chisel, Catalog $catalog, Settings $settings, Kernel $artisan): void
    {
        // Claim the run atomically, so two workers can't both start it.
        if (Run::query()->whereKey($this->runId)->where('status', Run::PENDING)->update(['status' => Run::RUNNING, 'startedAt' => now()]) !== 1) {
            return;
        }

        $run = Run::query()->findOrFail($this->runId);

        if (! app()->runningInConsole()) {
            $this->refuse($run, t('Chisel runs commands only in a queue worker (`php artisan queue:work --queue={queue}`), not in a web request.', ['queue' => $chisel->queue()], category: 'chisel'));

            return;
        }

        // A fresh signature list before checking, so a command removed since is refused.
        if (($at = $catalog->refreshedAt()) === null || $at->lt(now()->subMinutes(5))) {
            rescue(fn () => $catalog->refresh(), report: false);
        }

        $refusal = $chisel->disabledReason()
            ?? $chisel->commandRefusal($run->command)
            ?? ($chisel->userMayStillRun($run->userId, $run->command) ? null : t('The person who queued this can no longer run it.', category: 'chisel'));

        $params = $this->parameters($run);
        $spec = $catalog->get($run->command);

        if ($refusal === null && ($params === null || $spec === null || ! InputBuilder::paramsFit($spec, $params))) {
            $refusal = t('The command’s signature changed since this run was queued. Queue it again.', category: 'chisel');
        }

        if ($refusal !== null) {
            $this->refuse($run, $refusal);

            return;
        }

        $flush = function (string $text, bool $truncated) use ($run): void {
            Run::query()->whereKey($run->id)->update(['output' => $text, 'outputTruncated' => $truncated]);
        };
        $output = new StreamingOutput(new OutputSanitizer(Secrets::collect()), $flush, max(16, $settings->maxOutputKb) * 1024);

        $start = hrtime(true);
        $error = null;

        try {
            $exitCode = $artisan->call($run->command, [...$params, '--no-interaction' => true], $output);
        } catch (Throwable $e) {
            $exitCode = 1;
            $error = mb_substr(new OutputSanitizer(Secrets::collect())->clean(class_basename($e).': '.$e->getMessage()), 0, 250);
            Log::warning('Chisel: a command threw.', ['run' => $run->id, 'command' => $run->command, 'exception' => $e::class]);
        }

        $output->flush();

        Run::query()->whereKey($run->id)->update([
            'status' => $exitCode === 0 ? Run::SUCCEEDED : Run::FAILED,
            'exitCode' => max(-32768, min(32767, $exitCode)),
            'output' => $output->text(),
            'outputTruncated' => $output->truncated(),
            'error' => $error,
            'parameters' => null,
            'finishedAt' => now(),
            'durationMs' => (int) ((hrtime(true) - $start) / 1_000_000),
        ]);
    }

    /** The worker gave up on the job (timeout, crash, memory). */
    public function failed(?Throwable $e): void
    {
        Run::query()->whereKey($this->runId)->unfinished()->update([
            'status' => Run::FAILED,
            'error' => t('The queue worker stopped the command (it timed out or crashed).', category: 'chisel'),
            'parameters' => null,
            'finishedAt' => now(),
        ]);
    }

    private function refuse(Run $run, string $reason): void
    {
        Log::warning('Chisel: refused a queued run.', ['run' => $run->id, 'command' => $run->command]);
        Run::query()->whereKey($run->id)->update([
            'status' => Run::REFUSED,
            'error' => mb_substr($reason, 0, 250),
            'parameters' => null,
            'finishedAt' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function parameters(Run $run): ?array
    {
        if ($run->parameters === null) {
            return [];
        }

        try {
            $params = json_decode(Crypt::decryptString($run->parameters), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($params) ? $params : null;
    }

    public function displayName(): string
    {
        return 'Chisel run #'.$this->runId;
    }
}
