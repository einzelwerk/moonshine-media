<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Usages;

/**
 * What to call the record a file is used by.
 *
 * `type` is a key and not a caption: `page`, `shared_block`, `header`,
 * `footer`, `metadata`. This package must not know the admin panel, so it
 * hands over the key and the panel translates it.
 *
 * `url` is null here for the same reason: an address inside the admin is the
 * admin's business. `ownerType` and `ownerId` are what it needs to build one,
 * so that it does not have to walk back from the file to the page itself.
 */
final readonly class UsageOwnerLabel
{
    public function __construct(
        public string $title,
        public ?string $url = null,
        public string $type = 'unknown',
        public ?string $ownerType = null,
        public int|string|null $ownerId = null,
    ) {
    }

    /**
     * What a usage of a kind nobody registered a resolver for reads as. It
     * says enough to find the row by hand and never pretends the reference
     * is gone.
     */
    public static function fallback(string $usableType, int|string $usableId): self
    {
        return new self(
            title: $usableType.' #'.$usableId,
            url: null,
            type: $usableType,
        );
    }
}
