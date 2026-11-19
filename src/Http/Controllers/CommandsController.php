<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Commands\Catalog;
use JustinHoltWeb\Chisel\Commands\CommandPolicy;
use JustinHoltWeb\Chisel\Commands\CommandSpec;
use JustinHoltWeb\Chisel\Commands\InputBuilder;
use JustinHoltWeb\Chisel\Jobs\RefreshCatalog;
use JustinHoltWeb\Chisel\Models\Run;
use JustinHoltWeb\Chisel\Runs\History;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Chisel → Commands: the allowlisted commands, a form per command built from its
 * signature, and queueing a run.
 */
class CommandsController
{
    use RendersScreens;

    public function __construct(
        private readonly Chisel $chisel,
        private readonly Catalog $catalog,
        private readonly History $history,
    ) {}

    public function index(Request $request): CpScreenResponse
    {
        $user = self::user($request);
        $commands = [];
        foreach ($this->chisel->runnable() as $name) {
            if (! $this->chisel->canRun($user, $name)) {
                continue;
            }
            $spec = $this->chisel->spec($name);
            $last = $this->history->lastRun($name, $this->chisel->canViewHistory($user) ? null : $user->getCraftUserId());
            $commands[] = [
                'name' => $name,
                'description' => $spec->description ?? '',
                'risk' => $this->chisel->risk($name),
                'url' => cp_url('chisel/commands/'.$name),
                'last' => $last ? ['status' => $last->status, 'label' => self::statusLabel($last->status), 'url' => cp_url('chisel/runs/'.$last->id), 'when' => $last->created_at?->diffForHumans()] : null,
            ];
        }

        $unavailable = [];
        foreach (array_unique([...$this->chisel->allowlist(), ...$this->chisel->sensitiveAllowlist()]) as $name) {
            if (($reason = $this->chisel->commandRefusal($name)) !== null) {
                $unavailable[] = ['name' => $name, 'reason' => $reason];
            }
        }

        return $this->screen($request, 'commands', t('Commands', category: 'chisel'), template('chisel/_commands', [
            'commands' => $commands,
            'unavailable' => $unavailable,
            'canRun' => $user->can(Chisel::PERMISSION_RUN),
            'catalogAt' => $this->catalog->refreshedAt(),
            'catalogEmpty' => $this->catalog->isEmpty(),
            'refreshUrl' => cp_url('chisel/catalog/refresh'),
            'queue' => $this->chisel->queue(),
            'queueShared' => $this->chisel->queueSharedWithWebRunner(),
            ...$this->elevationVars($user),
        ]));
    }

    public function show(Request $request, string $name): CpScreenResponse
    {
        $user = self::user($request);
        $spec = $this->runnableSpec($user, $name);

        /** @var ViewErrorBag $bag */
        $bag = $request->session()->get('errors') ?? new ViewErrorBag;
        $errors = $bag->getBag('default');

        // Other people's runs only for those who may see the history.
        $recent = Run::query()->where('command', $spec->name)
            ->when(! $this->chisel->canViewHistory($user), fn ($q) => $q->where('userId', $user->getCraftUserId()))
            ->latest('id')->limit(5)->get()
            ->map(fn (Run $r) => self::runRow($r))->all();

        return $this->screen($request, 'commands', $spec->name, template('chisel/_command', [
            'spec' => $spec,
            'fields' => $this->fields($spec, $request),
            'errors' => $errors->toArray(),
            'risk' => $this->chisel->risk($spec->name),
            'action' => cp_url('chisel/commands/'.$spec->name),
            'recent' => $recent,
            'canHistory' => $this->chisel->canViewHistory($user),
            ...$this->elevationVars($user),
        ]), [[t('Commands', category: 'chisel'), cp_url('chisel')]]);
    }

    public function run(Request $request, string $name): RedirectResponse
    {
        $user = self::user($request);
        $spec = $this->runnableSpec($user, $name);
        $back = cp_url('chisel/commands/'.$spec->name);
        $keep = fn (RedirectResponse $r) => $r->withInput($request->only(['args', 'opts']));

        if (($reason = $this->chisel->disabledReason()) !== null) {
            return redirect($back)->with('error', $reason);
        }

        if (! $this->chisel->isElevated()) {
            $password = $request->input('password');
            if (! is_string($password) || ! $this->chisel->elevate($user, $password)) {
                return $keep(redirect($back))->withErrors(['password' => $this->passwordError($user)]);
            }
        }

        if ($this->chisel->risk($spec->name) === CommandPolicy::CONFIRM && $request->input('confirm') !== $spec->name) {
            return $keep(redirect($back))->withErrors(['confirm' => t('Type “{name}” to confirm.', ['name' => $spec->name], category: 'chisel')]);
        }

        $builder = new InputBuilder($spec, $this->siteHandles(), fn (string $m, array $p) => t($m, $p, category: 'chisel'), $this->chisel->extraOptions($spec->name));
        $args = $request->input('args', []);
        $opts = $request->input('opts', []);
        $built = $builder->build(is_array($args) ? $args : [], is_array($opts) ? $opts : []);

        if ($built === null) {
            return $keep(redirect($back))->withErrors($builder->errors);
        }

        if (($existing = $this->history->inFlight((int) $user->getCraftUserId(), $spec->name)) !== null) {
            return redirect(cp_url('chisel/runs/'.$existing->id))
                ->with('notice', t('You already have a run of `{name}` waiting or running.', ['name' => $spec->name], category: 'chisel'));
        }

        $run = $this->history->queue($user, $spec->name, $built['params'], $built['display'], $request->ip());
        Log::info('Chisel: queued a command.', ['run' => $run->id, 'command' => $run->command]);

        return redirect(cp_url('chisel/runs/'.$run->id))->with('success', t('Queued `{name}`.', ['name' => $spec->name], category: 'chisel'));
    }

    public function elevate(Request $request): RedirectResponse
    {
        $user = self::user($request);
        $password = $request->input('password');
        $to = cp_url($request->input('return') === 'settings' ? 'chisel/settings' : 'chisel');

        if (! is_string($password) || ! $this->chisel->elevate($user, $password)) {
            return redirect($to)->withErrors(['password' => $this->passwordError($user)]);
        }

        return redirect($to)->with('success', t('Password confirmed.', category: 'chisel'));
    }

    public function refreshCatalog(): RedirectResponse
    {
        RefreshCatalog::dispatch()->onConnection($this->chisel->connection())->onQueue($this->chisel->queue());

        return redirect(cp_url('chisel'))->with('success', t('The command list will refresh when the queue worker picks it up.', category: 'chisel'));
    }

    private function passwordError(CraftUser $user): string
    {
        return $this->chisel->elevationLockedOut($user)
            ? t('Too many wrong passwords. Try again in a minute.', category: 'chisel')
            : t('Enter your password to confirm it’s you.', category: 'chisel');
    }

    /** The command, if it's allowlisted, available here and this person may run it; else 404/403. */
    private function runnableSpec(CraftUser $user, string $name): CommandSpec
    {
        abort_if($this->chisel->commandRefusal($name) !== null, 404);
        abort_unless($this->chisel->canRun($user, $name), 403);

        return $this->chisel->spec($name) ?? abort(404);
    }

    /** @return list<string> */
    private function siteHandles(): array
    {
        return Sites::getAllSites()->map(fn ($site) => (string) $site->handle)->values()->all();
    }

    /**
     * Form fields for the template, with old input.
     *
     * @return list<array{kind: string, name: string, label: string, input: string, description: string, required: bool, value: mixed, options?: list<array{label: string, value: string}>}>
     */
    private function fields(CommandSpec $spec, Request $request): array
    {
        $old = fn (string $key, mixed $default) => $request->session()->getOldInput($key, $default);
        $fields = [];

        foreach ($spec->arguments as $a) {
            $default = $a['default'];
            $fields[] = [
                'kind' => $a['array'] ? 'textarea' : 'text',
                'name' => 'args['.$a['name'].']',
                'label' => $a['name'],
                'input' => 'args.'.$a['name'],
                'description' => $a['description'].($a['array'] ? ' '.t('One per line.', category: 'chisel') : ''),
                'required' => $a['required'],
                'value' => $old('args.'.$a['name'], is_array($default) ? implode("\n", $default) : $default),
            ];
        }

        foreach ($spec->options as $o) {
            $base = [
                'name' => 'opts['.$o['name'].']',
                'label' => '--'.$o['name'],
                'input' => 'opts.'.$o['name'],
                'description' => $o['description'],
                'required' => false,
            ];

            if ($o['value'] === 'none') {
                $fields[] = [...$base, 'kind' => 'switch', 'value' => (bool) $old('opts.'.$o['name'], false)];
            } elseif ($o['name'] === 'site') {
                $options = [['label' => t('(not set)', category: 'chisel'), 'value' => '']];
                foreach (Sites::getAllSites() as $site) {
                    $options[] = ['label' => $site->getName().' ('.$site->handle.')', 'value' => (string) $site->handle];
                }
                $fields[] = [...$base, 'kind' => 'select', 'value' => $old('opts.site', ''), 'options' => $options];
            } else {
                $default = $o['default'];
                $fields[] = [
                    ...$base,
                    'kind' => $o['array'] ? 'textarea' : 'text',
                    'description' => $o['description'].($o['array'] ? ' '.t('One per line.', category: 'chisel') : ''),
                    'value' => $old('opts.'.$o['name'], is_array($default) ? implode("\n", $default) : (is_string($default) ? $default : '')),
                ];
            }
        }

        return $fields;
    }
}
