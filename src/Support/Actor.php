<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

use Ewk\Cms\Media\Models\CmsMedia;

/**
 * Who is acting on a file.
 *
 * There are exactly two answers today: a user of the admin panel, who sees
 * the whole library, and the owner of a file, who sees nothing but their own.
 * The actions of the package ask an actor instead of trusting the caller, so
 * that no route can reach a file by going around the check.
 */
final readonly class Actor
{
    private function __construct(
        public bool $admin,
        public ?int $userId,
        public ?string $ownerType,
        public int|string|null $ownerId,
    ) {
    }

    /**
     * A user of the admin panel. The identifier is optional because it is
     * written into `uploaded_by` and is not what grants the access.
     */
    public static function admin(?int $userId = null): self
    {
        return new self(admin: true, userId: $userId, ownerType: null, ownerId: null);
    }

    /**
     * The owner of the files, addressed the way `cms_media` stores it: the
     * morph alias of the model and its key.
     */
    public static function owner(string $type, int|string $id): self
    {
        return new self(admin: false, userId: null, ownerType: $type, ownerId: $id);
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function owns(CmsMedia $media): bool
    {
        if ($this->ownerType === null || $media->owner_type !== $this->ownerType) {
            return false;
        }

        // The key is compared as a string: a route hands over «17» and the
        // column holds 17, and an owner must not depend on which of the two
        // reached this method.
        return $media->owner_id !== null && (string) $media->owner_id === (string) $this->ownerId;
    }

    public function canManage(CmsMedia $media): bool
    {
        return $this->admin || $this->owns($media);
    }
}
