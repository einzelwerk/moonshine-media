<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine;

use Ewk\Cms\Media\MoonShine\Library\UsageLinkRegistry;
use Ewk\Cms\Media\MoonShine\Pages\MediaLibraryPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MoonShine\Contracts\Core\DependencyInjection\ConfiguratorContract;
use MoonShine\Contracts\Core\DependencyInjection\CoreContract;

/**
 * The admin of the media library inside a MoonShine panel: the «Медиа»
 * section, its endpoints, the fields that open it, their views, words and
 * configuration.
 *
 * The core of the library has a provider of its own,
 * {@see \Ewk\Cms\Media\CmsMediaServiceProvider}, which knows nothing of the
 * panel.
 */
final class MoonShineMediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/moonshine-media.php', 'moonshine-media');

        // Where the packages that own records say which screen edits them
        // and what their usages are called. Bound here and extended there,
        // so that no order of registration can lose one.
        $this->app->singleton(UsageLinkRegistry::class);
    }

    public function boot(CoreContract $core): void
    {
        // The core merges what every provider registers, so the panel of the
        // application keeps its own pages next to this one.
        $core->pages([MediaLibraryPage::class]);

        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'moonshine-media');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'moonshine-media');
        $this->registerRoutes();

        $this->publishes([
            __DIR__.'/../../config/moonshine-media.php' => config_path('moonshine-media.php'),
        ], 'moonshine-media-config');

        $this->publishes([
            __DIR__.'/../../resources/lang' => $this->app->langPath('vendor/moonshine-media'),
        ], 'moonshine-media-lang');

        $this->publishes([
            __DIR__.'/../../resources/views' => $this->app->resourcePath('views/vendor/moonshine-media'),
        ], 'moonshine-media-views');
    }

    /**
     * The endpoints of the library hang inside the panel: same domain, same
     * prefix, same middleware, plus the authentication of the panel. An
     * installation that switched the routes of MoonShine off gets none of
     * these either.
     */
    private function registerRoutes(): void
    {
        $configurator = $this->app->make(ConfiguratorContract::class);

        if (! $configurator->isUseRoutes()) {
            return;
        }

        $declared = $configurator->getDefaultRouteGroup();

        $group = array_filter([
            'domain' => $declared['domain'] ?? null,
            'prefix' => $declared['prefix'] ?? null,
            'as' => $declared['as'] ?? null,
        ]) + ['middleware' => ['moonshine', ...$configurator->getAuthMiddleware()]];

        Route::group($group, function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/moonshine.php');
        });
    }
}
