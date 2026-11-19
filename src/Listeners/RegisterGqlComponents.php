<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Listeners;

use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Gql\RunsQuery;

use function CraftCms\Cms\t;

/**
 * One schema permission: “View Chisel run history”. Off for every schema until ticked.
 * Signed-in GraphQL requests get it only if the user could open Chisel → History.
 */
class RegisterGqlComponents
{
    public function handle(GqlSchemaComponentsResolving $event): void
    {
        $event->queries['Chisel'] = [
            RunsQuery::COMPONENT => [
                'label' => t('View Chisel run history', category: 'chisel'),
                'info' => t('Adds the read-only `chiselRuns` query: command names, statuses, exit codes and timings. Never arguments, output or who ran them.', category: 'chisel'),
                'authenticated' => fn (User $user): bool => app(Chisel::class)->canViewHistory($user),
            ],
        ];
    }
}
