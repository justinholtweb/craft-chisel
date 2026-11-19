<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Http\Request;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Models\Run;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * @property-read Chisel $chisel
 */
trait RendersScreens
{
    /**
     * @param  list<array{string, string}>  $crumbs  [label, url] between “Chisel” and the page
     */
    private function screen(Request $request, string $section, string $title, string $html, array $crumbs = []): CpScreenResponse
    {
        $response = new CpScreenResponse()
            ->title($title)
            ->docTitle($title.' - Chisel')
            ->addCrumb('Chisel', cp_url('chisel'));

        foreach ($crumbs as [$label, $url]) {
            $response->addCrumb($label, $url);
        }

        $notice = $this->chisel->disabledReason();
        $banner = $notice !== null
            ? '<craft-callout variant="warning" id="chisel-disabled" class="mb-4">'.e($notice).' '.e(t('Run history stays readable.', category: 'chisel')).'</craft-callout>'
            : '';

        return $response->contentHtml('<div class="chisel" data-section="'.e($section).'">'.$banner.$html.'</div>');
    }

    /** @return array{elevated: bool, elevationRemaining: int, elevationSeconds: int, elevateUrl: string, lockedOut: bool} */
    private function elevationVars(CraftUser $user): array
    {
        return [
            'elevated' => $this->chisel->isElevated(),
            'elevationRemaining' => $this->chisel->elevationRemaining(),
            'elevationSeconds' => $this->chisel->elevationSeconds(),
            'elevateUrl' => cp_url('chisel/elevate'),
            'lockedOut' => $this->chisel->elevationLockedOut($user),
        ];
    }

    private static function user(Request $request): CraftUser
    {
        return $request->craftUser() ?? abort(403);
    }

    /** @return array<string, mixed> */
    private static function runRow(Run $run): array
    {
        return [
            'id' => $run->id,
            'url' => cp_url('chisel/runs/'.$run->id),
            'command' => $run->command,
            'commandLine' => $run->commandLine(),
            'status' => $run->status,
            'statusLabel' => self::statusLabel($run->status),
            'exitCode' => $run->exitCode,
            'username' => $run->username,
            'created' => $run->created_at?->toIso8601String(),
            'createdHuman' => $run->created_at?->diffForHumans(),
            'durationMs' => $run->durationMs,
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Run::PENDING => t('Waiting', category: 'chisel'),
            Run::RUNNING => t('Running', category: 'chisel'),
            Run::SUCCEEDED => t('Succeeded', category: 'chisel'),
            Run::FAILED => t('Failed', category: 'chisel'),
            Run::REFUSED => t('Refused', category: 'chisel'),
            default => $status,
        };
    }
}
