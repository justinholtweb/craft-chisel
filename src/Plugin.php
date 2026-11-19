<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Gql\GqlQueries;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use JustinHoltWeb\Chisel\Http\Requests\CoreSettingsRequest;
use Override;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Chisel — run allowlisted Artisan commands from the Craft control panel: a form built
 * from each command's signature, queued runs with live, sanitised output, and a full
 * audit log. Craft 6 only.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    protected array $events = [
        TemplateGlobalsResolving::class => Listeners\RegisterTemplateApi::class,
        GqlSchemaComponentsResolving::class => Listeners\RegisterGqlComponents::class,
    ];

    protected array $commands = [
        Console\RefreshCommand::class,
        Console\PruneCommand::class,
    ];

    protected array $scripts = [
        __DIR__.'/../resources/js/chisel.js' => 'js/chisel.js',
    ];

    protected array $styles = [
        __DIR__.'/../resources/css/chisel.css' => 'css/chisel.css',
    ];

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());
        $this->app->singleton(Chisel::class);
        $this->app->singleton(Commands\Catalog::class);
        $this->hasCpSection = $this->getSettings()->showInCpNav;
        $this->app->make(GqlQueries::class)->register(Gql\RunsQuery::class);
    }

    /**
     * Settings → Plugins → Chisel opens Chisel's own settings page, which needs an
     * elevated session to save; Craft's generic save endpoint is refused outright.
     */
    #[Override]
    public function getSettingsResponse(): mixed
    {
        return new RedirectResponse(cp_url('chisel/settings'));
    }

    #[Override]
    public function getSettingsRequestClass(): ?string
    {
        return CoreSettingsRequest::class;
    }

    /** @return list<Permission> */
    #[Override]
    protected function getPermissions(): array
    {
        $chisel = $this->app->make(Chisel::class);
        $names = array_unique([...$chisel->allowlist(), ...$chisel->sensitiveAllowlist()]);
        sort($names);

        return [
            new Permission(
                key: Chisel::PERMISSION_RUN,
                label: t('Run Artisan commands', category: 'chisel'),
                warning: t('Only admins can use Chisel unless `requireAdmin` is off in `config/craft/chisel.php`.', category: 'chisel'),
                nested: collect(array_map(fn (string $name) => new Permission(
                    Chisel::commandPermission($name),
                    t('Run `{name}`', ['name' => $name], category: 'chisel'),
                ), $names)),
            ),
            new Permission(
                key: Chisel::PERMISSION_HISTORY,
                label: t('View Chisel run history', category: 'chisel'),
                info: t('Every run’s command line and output, from everyone.', category: 'chisel'),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $chisel = $this->app->make(Chisel::class);
        $user = currentUser();

        if (! $chisel->canAccess($user)) {
            return null;
        }

        $settings = $this->getSettings();
        $subnav = [['label' => t('Commands', category: 'chisel'), 'url' => 'chisel']];
        if ($chisel->canViewHistory($user)) {
            $subnav[] = ['label' => t('History', category: 'chisel'), 'url' => 'chisel/history'];
        }
        if ($user?->isAdmin()) {
            $subnav[] = ['label' => t('Settings', category: 'chisel'), 'url' => 'chisel/settings'];
        }

        return new NavItem([
            'label' => $settings->navLabel() ?? $this->name,
            'href' => 'chisel',
            'icon' => $settings->navIcon() ?? $this->cpNavIconPath(),
            'subnav' => $subnav,
        ]);
    }

    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('craft:chisel:prune')->daily()->withoutOverlapping();
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }
}
