<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine;

use Ewk\Cms\Media\CmsMediaServiceProvider;
use Ewk\Cms\Media\MoonShine\MoonShineMediaServiceProvider;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner;
use Ewk\Cms\Media\Tests\MoonShine\Fixtures\MoonShine\TestMoonShineServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Providers\MoonShineServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * The admin of the library runs inside a MoonShine panel, so the base case
 * boots one: its provider, the two providers of this package, an application
 * provider that registers the test resources, and an authenticated
 * administrator. The HTTP tests then drive the real MoonShine routes.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            MoonShineServiceProvider::class,
            CmsMediaServiceProvider::class,
            MoonShineMediaServiceProvider::class,
            TestMoonShineServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // The production database enforces foreign keys, so the tests do
            // too: a variant and a usage both point at a record of the library.
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // The fields draw one description per content language, and the
        // suite reads the panel in Russian with English beside it.
        $app['config']->set('cms-media.locales', ['ru', 'en']);
        $app['config']->set('cms-media.default_locale', 'ru');

        // The notification centre of the panel needs the Laravel
        // notifications table, which has nothing to do with the library.
        $app['config']->set('moonshine.use_notifications', false);
        $app['config']->set('moonshine.use_database_notifications', false);
    }

    /**
     * Tables of the records the fields are drawn on. The package ships
     * neither: an application brings its own models.
     */
    protected function defineDatabaseMigrations(): void
    {
        // A record that owns pictures — a venue, a tour — standing in for a
        // model of the installation, see \Ewk\Cms\Media\Tests\MoonShine\Fixtures\GalleryOwner.
        if (! Schema::hasTable('test_gallery_owners')) {
            Schema::create('test_gallery_owners', function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                // A name in every language, the way a record of the
                // application carries one: the listing reads it in the
                // language of the panel.
                $table->json('name')->nullable();
            });
        }

        // A record that keeps references to pictures in columns of its own,
        // see \Ewk\Cms\Media\Tests\MoonShine\Fixtures\Poster.
        if (! Schema::hasTable('test_posters')) {
            Schema::create('test_posters', function (Blueprint $table): void {
                $table->id();
                $table->string('title')->nullable();
                $table->json('image')->nullable();
                $table->json('og_image')->nullable();
                $table->json('slides')->nullable();
            });
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The library knows an owner of pictures by its morph alias alone,
        // exactly as an installation registers its own.
        Relation::morphMap([GalleryOwner::MORPH_ALIAS => GalleryOwner::class]);
    }

    protected function actingAsAdmin(): static
    {
        $this->actingAs(MoonshineUser::factory()->create(), 'moonshine');

        return $this;
    }
}
