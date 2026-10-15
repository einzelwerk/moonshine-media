<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Ewk\Cms\Media\Usages\UsageOwnerLabel;
use MoonShine\Contracts\Core\CrudResourceWithPagesContract;
use Throwable;

/**
 * Where «used in» sends an editor.
 *
 * The core of the library hands over the morph alias and the key of the
 * record and deliberately no address: it must not know the admin panel.
 * Building one is this side of the wall, and it is a lookup in
 * {@see UsageLinkRegistry}, which the packages that own records fill.
 *
 * An alias nothing maps is not an error: the name still reads, the line
 * simply does not become a link.
 */
final readonly class UsageLinks
{
    public function __construct(private UsageLinkRegistry $registry)
    {
    }

    /**
     * What kind of record the line of the list names, translated from the
     * key the resolver chose. A caption registered for the key comes first,
     * then `moonshine-media::library.usage_types`, where an application lays
     * the captions of its own records over the ones of this package.
     */
    public function caption(UsageOwnerLabel $label): string
    {
        $registered = $this->registry->captionFor($label->type);

        if ($registered !== null) {
            return $registered;
        }

        $key = 'moonshine-media::library.usage_types.'.$label->type;
        $caption = __($key);

        return is_string($caption) && $caption !== $key
            ? $caption
            : (string) __('moonshine-media::library.usage_types.unknown');
    }

    public function url(UsageOwnerLabel $label): ?string
    {
        // A resolver may already know the address of its own record; the
        // registry is the fallback.
        if ($label->url !== null && $label->url !== '') {
            return $label->url;
        }

        if ($label->ownerType === null || $label->ownerId === null) {
            return null;
        }

        $resource = $this->registry->resourceFor($label->ownerType);

        if ($resource === null) {
            return null;
        }

        try {
            $instance = app($resource);

            return $instance instanceof CrudResourceWithPagesContract ? $instance->getFormPageUrl($label->ownerId) : null;
        } catch (Throwable) {
            // A resource an installation switched off leaves the line as
            // plain text; an editor still reads which record it is.
            return null;
        }
    }
}
