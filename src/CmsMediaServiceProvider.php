<?php

declare(strict_types=1);

namespace Ewk\Cms\Media;

use Ewk\Cms\Media\Console\GenerateMissingVariantsCommand;
use Ewk\Cms\Media\Contracts\MediaDriverContract;
use Ewk\Cms\Media\Contracts\VideoFramesContract;
use Ewk\Cms\Media\Drivers\LocalDriver;
use Ewk\Cms\Media\Localization\ConfigLanguageCatalog;
use Ewk\Cms\Media\Localization\LanguageCatalog;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Models\CmsMediaVariant;
use Ewk\Cms\Media\Services\MediaAttachments;
use Ewk\Cms\Media\Services\MediaUsageRegistrar;
use Ewk\Cms\Media\Support\DiskRegistry;
use Ewk\Cms\Media\Support\KindRegistry;
use Ewk\Cms\Media\Support\PresetRegistry;
use Ewk\Cms\Media\Upload\ImageManagerFactory;
use Ewk\Cms\Media\Usages\UsageLabelRegistry;
use Ewk\Cms\Media\Video\FfmpegVideoFrames;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class CmsMediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cms-media.php', 'cms-media');

        $this->app->bind(MediaDriverContract::class, LocalDriver::class);

        // One per process: whether the tools are installed is asked once and
        // not on every variant of every file.
        $this->app->singleton(VideoFramesContract::class, FfmpegVideoFrames::class);

        // Where the packages that own records say what those records are
        // called in the list of «where this file is used». Bound here and
        // extended there, so that no order of registration can lose one.
        $this->app->singleton(UsageLabelRegistry::class);

        // Bound by name and not left to autowiring: whoever holds a reference
        // to a file asks the container whether the accounting of usages is
        // available at all, and an unbound concrete class answers «no».
        $this->app->singleton(MediaUsageRegistrar::class);

        // The writing side of the pictures a record owns, beside the
        // accounting of the references a block holds. Bound by name for the
        // same reason: the trait on a model of the installation asks the
        // container for it, and a model must not construct a service itself.
        $this->app->singleton(MediaAttachments::class);

        // The content languages a description of a file is written in. The
        // default reads them from configuration; an application that keeps
        // its languages elsewhere binds its own catalog, and a binding that
        // is already there is left alone.
        $this->app->singletonIf(LanguageCatalog::class, ConfigLanguageCatalog::class);

        $this->app->singleton(
            PresetRegistry::class,
            static fn (): PresetRegistry => new PresetRegistry(Config::array('cms-media.presets', [])),
        );

        // The storages and the allowlist of kinds are read once and asked of
        // the container afterwards: whoever validates an upload must see the
        // same list the panel offered, and a request must never be able to
        // widen either of them.
        $this->app->singleton(
            DiskRegistry::class,
            static fn (): DiskRegistry => new DiskRegistry(Config::array('cms-media.disks', [])),
        );

        $this->app->singleton(
            KindRegistry::class,
            static fn (): KindRegistry => new KindRegistry(Config::array('cms-media.kinds', [])),
        );

        // Whatever decodes a picture asks this for a manager instead of
        // reading the configuration itself, so that the driver and the
        // processor of an upload always share one backend.
        $this->app->singleton(ImageManagerFactory::class);
    }

    public function boot(): void
    {
        // The polymorphic columns of the library carry aliases and not class
        // names, so that a model may be renamed without a data migration.
        Relation::morphMap([
            CmsMedia::MORPH_ALIAS => CmsMedia::class,
            CmsMediaFolder::MORPH_ALIAS => CmsMediaFolder::class,
            CmsMediaUsage::MORPH_ALIAS => CmsMediaUsage::class,
            CmsMediaVariant::MORPH_ALIAS => CmsMediaVariant::class,
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateMissingVariantsCommand::class]);
        }
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cms-media');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'cms-media-migrations');

        $this->publishes([
            __DIR__.'/../config/cms-media.php' => config_path('cms-media.php'),
        ], 'cms-media-config');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/cms-media'),
        ], 'cms-media-lang');
    }
}
