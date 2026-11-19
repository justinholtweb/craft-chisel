<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Models\Run;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Chisel → History and single runs (with live output while running).
 */
class RunsController
{
    use RendersScreens;

    public const int PER_PAGE = 50;

    public function __construct(private readonly Chisel $chisel) {}

    public function index(Request $request): CpScreenResponse
    {
        [$status, $command] = $this->filters($request);
        $page = max(1, $request->integer('page', 1));

        $query = $this->query($status, $command);
        $total = (clone $query)->count();
        $runs = $query->latest('id')->forPage($page, self::PER_PAGE)->get()->map(fn (Run $r) => self::runRow($r))->all();
        $base = fn (int $p) => cp_url('chisel/history', array_filter(['status' => $status, 'command' => $command, 'page' => $p > 1 ? $p : null]));

        return $this->screen($request, 'history', t('History', category: 'chisel'), template('chisel/_history', [
            'runs' => $runs,
            'total' => $total,
            'status' => $status,
            'command' => $command,
            'statuses' => array_map(fn (string $s) => ['value' => $s, 'label' => self::statusLabel($s)], Run::STATUSES),
            'prevUrl' => $page > 1 ? $base($page - 1) : null,
            'nextUrl' => $page * self::PER_PAGE < $total ? $base($page + 1) : null,
            'exportUrl' => cp_url('chisel/history/export', array_filter(['status' => $status, 'command' => $command])),
            'actionUrl' => cp_url('chisel/history'),
        ]));
    }

    public function show(Request $request, int $id): CpScreenResponse
    {
        $run = $this->visibleRun($request, $id);

        return $this->screen($request, 'history', '#'.$run->id.' '.$run->command, template('chisel/_run', [
            'run' => self::runRow($run),
            'output' => (string) $run->output,
            'truncated' => $run->outputTruncated,
            'error' => $run->error,
            'finished' => $run->isFinished(),
            'startedAt' => $run->startedAt?->toIso8601String(),
            'finishedAt' => $run->finishedAt?->toIso8601String(),
            'pollUrl' => cp_url('chisel/runs/'.$run->id.'/poll'),
            'againUrl' => $this->chisel->canRun(self::user($request), $run->command) && $this->chisel->commandRefusal($run->command) === null
                ? cp_url('chisel/commands/'.$run->command) : null,
            'queue' => $this->chisel->queue(),
            'pendingSeconds' => $run->status === Run::PENDING && $run->created_at ? (int) $run->created_at->diffInSeconds(now()) : 0,
        ]), $this->chisel->canViewHistory(self::user($request)) ? [[t('History', category: 'chisel'), cp_url('chisel/history')]] : []);
    }

    /** JSON for the live view. Output is sent only when its length changed since `length`. */
    public function poll(Request $request, int $id): JsonResponse
    {
        $run = $this->visibleRun($request, $id);
        $output = (string) $run->output;
        $changed = strlen($output) !== $request->integer('length', -1);

        return new JsonResponse([
            'status' => $run->status,
            'statusLabel' => self::statusLabel($run->status),
            'finished' => $run->isFinished(),
            'exitCode' => $run->exitCode,
            'durationMs' => $run->durationMs,
            'error' => $run->error,
            'truncated' => $run->outputTruncated,
            'length' => strlen($output),
            'output' => $changed ? $output : null,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /** The audit log as CSV (no output bodies). */
    public function export(Request $request): StreamedResponse
    {
        [$status, $command] = $this->filters($request);
        $query = $this->query($status, $command)->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'uid', 'command', 'arguments', 'status', 'exitCode', 'userId', 'username', 'ip', 'queuedAt', 'startedAt', 'finishedAt', 'durationMs', 'error'], escape: '');
            $query->chunk(500, function ($runs) use ($out): void {
                foreach ($runs as $run) {
                    /** @var Run $run */
                    fputcsv($out, array_map(self::csvCell(...), [
                        $run->id, $run->uid, $run->command, implode(' ', $run->arguments ?? []), $run->status, $run->exitCode,
                        $run->userId, $run->username, $run->ip, $run->created_at?->toIso8601String(),
                        $run->startedAt?->toIso8601String(), $run->finishedAt?->toIso8601String(), $run->durationMs, $run->error,
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'chisel-history-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Spreadsheet formula injection: a cell starting with = + - @ (or tab/CR) is prefixed with '. */
    public static function csvCell(mixed $value): string
    {
        $value = (string) (is_scalar($value) ? $value : '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function visibleRun(Request $request, int $id): Run
    {
        $run = Run::query()->find($id) ?? abort(404);
        $user = self::user($request);

        abort_unless($this->chisel->canViewHistory($user) || $this->isOwn($run, $user), 404);

        return $run;
    }

    private function isOwn(Run $run, CraftUser $user): bool
    {
        return $run->userId !== null && $run->userId === $user->getCraftUserId() && $user->can(Chisel::PERMISSION_RUN);
    }

    /** @return array{?string, ?string} */
    private function filters(Request $request): array
    {
        $status = in_array($request->query('status'), Run::STATUSES, true) ? (string) $request->query('status') : null;
        $command = is_string($c = $request->query('command')) && preg_match('/^[a-z0-9:_.\-]{1,128}$/D', $c) ? $c : null;

        return [$status, $command];
    }

    /** @return Builder<Run> */
    private function query(?string $status, ?string $command): Builder
    {
        return Run::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($command !== null, fn ($q) => $q->where('command', $command));
    }
}
