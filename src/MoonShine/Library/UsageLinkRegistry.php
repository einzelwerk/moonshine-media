<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Closure;
use MoonShine\Contracts\Core\CrudResourceContract;

/**
 * What «used in» knows about the records of other packages: which resource
 * of the panel edits a record of a morph alias, and what a kind of usage is
 * called on the screen.
 *
 * The media library itself owns no record a file can be used by, so it maps
 * nothing. A package that does — a CMS with its pages and blocks — fills this
 * registry from its own provider; bound here as a singleton and extended
 * there, so that no order of registration can lose an entry:
 *
 *     $this->app->extend(UsageLinkRegistry::class, static fn (UsageLinkRegistry $links): UsageLinkRegistry => $links
 *         ->resource(Page::MORPH_ALIAS, PageResource::class)
 *         ->caption('page', 'my-package::usages.page'));
 *
 * A record nothing here maps still reads by its name; the line simply does
 * not become a link. A resolver that already knows the address of its own
 * record hands it over in {@see \Ewk\Cms\Media\Usages\UsageOwnerLabel::$url}
 * and needs no entry at all.
 */
final class UsageLinkRegistry
{
    /** @var array<string, class-string<CrudResourceContract>|Closure(): class-string<CrudResourceContract>> */
    private array $resources = [];

    /** @var array<string, string|Closure(): string> */
    private array $captions = [];

    /**
     * The resource whose form page a usage of this morph alias links to. A
     * closure is asked when a link is built, for a resource whose class is a
     * matter of configuration.
     *
     * @param  class-string<CrudResourceContract>|Closure(): class-string<CrudResourceContract>  $resource
     */
    public function resource(string $morphAlias, string|Closure $resource): static
    {
        $this->resources[$morphAlias] = $resource;

        return $this;
    }

    /**
     * What a kind of usage is called, by the `type` key of
     * {@see \Ewk\Cms\Media\Usages\UsageOwnerLabel}. A string is a key of the
     * translations and is read in the language of the panel; a closure
     * answers with the caption itself.
     *
     * @param  string|Closure(): string  $caption
     */
    public function caption(string $type, string|Closure $caption): static
    {
        $this->captions[$type] = $caption;

        return $this;
    }

    /**
     * @return class-string<CrudResourceContract>|null
     */
    public function resourceFor(string $morphAlias): ?string
    {
        $resource = $this->resources[$morphAlias] ?? null;

        return $resource instanceof Closure ? $resource() : $resource;
    }

    /**
     * `null` for a type nothing registered, and for a key that has no
     * translation: the caller then falls back to a caption of its own.
     */
    public function captionFor(string $type): ?string
    {
        $caption = $this->captions[$type] ?? null;

        if ($caption instanceof Closure) {
            return $caption();
        }

        if ($caption === null) {
            return null;
        }

        $translated = __($caption);

        return is_string($translated) && $translated !== $caption ? $translated : null;
    }
}
