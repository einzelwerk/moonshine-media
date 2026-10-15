<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A folder of the library.
 *
 * Folders are virtual: they order what the editor sees and have no say in
 * where the bytes are. A path on a disk is built from the uuid alone, so
 * renaming or moving a folder changes no address at all.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property-read CmsMediaFolder|null $parent
 * @property-read Collection<int, CmsMediaFolder> $children
 * @property-read Collection<int, CmsMedia> $media
 */
final class CmsMediaFolder extends Model
{
    /** @see CmsMedia::MORPH_ALIAS */
    public const string MORPH_ALIAS = 'cms_media_folder';

    protected $fillable = [
        'parent_id',
        'name',
    ];

    /**
     * @return BelongsTo<CmsMediaFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Alphabetical and not by a column of order: a tree of folders is read,
     * not arranged, so there is nothing for an editor to drag into place.
     *
     * @return HasMany<CmsMediaFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * @return HasMany<CmsMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(CmsMedia::class, 'folder_id');
    }

    /**
     * The chain up to the root, root first: exactly the order the breadcrumbs
     * of the panel are drawn in.
     *
     * The guard on visited identifiers is not paranoia about the data but
     * about a loop: a cycle written by hand would otherwise hang the request
     * that reads it instead of showing a broken tree.
     *
     * @return Collection<int, CmsMediaFolder>
     */
    public function ancestors(): Collection
    {
        /** @var Collection<int, CmsMediaFolder> $chain */
        $chain = new Collection();
        $seen = [$this->id => true];
        $folder = $this->parent;

        while ($folder instanceof self && ! isset($seen[$folder->id])) {
            $seen[$folder->id] = true;
            $chain->prepend($folder);
            $folder = $folder->parent;
        }

        return $chain;
    }

    /**
     * Only an empty folder may be deleted: a recursive delete would take files
     * someone else put there.
     */
    public function isEmpty(): bool
    {
        return ! $this->media()->exists() && ! $this->children()->exists();
    }

    /**
     * What keeps a folder from being moved into its own subtree, which would
     * detach the whole branch from the root.
     */
    public function isDescendantOf(self $folder): bool
    {
        return $this->ancestors()->contains(
            static fn (self $ancestor): bool => $ancestor->id === $folder->id
        );
    }
}
