<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Services;

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Writing the pictures a record owns.
 *
 * An attachment is a row of `cms_media_usages`: `field` is the role, `path`
 * is the language of that role or an empty string when it has none, and
 * `position` is the order. The reading side is
 * {@see \Ewk\Cms\Media\Concerns\HasMediaAttachments}.
 *
 * This lives beside {@see MediaUsageRegistrar} and never overlaps with it.
 * The registrar writes the references a block holds: its `field` is the
 * column of the record (`content`, `og_image`) and its `path` is the dotted
 * place inside that column (`items.0.image`), and it never writes a position.
 * Here `field` is a role an editor would name and `position` is always
 * written, which is what {@see CmsMediaUsage::isAttachment()} reads. Both
 * kinds of row are usages, so both of them keep a file from being deleted and
 * both appear in «where is this file used».
 *
 * Every write is scoped to one owner, one role and one language, so a form
 * that edits the Russian cover cannot touch the English one, and a card with
 * a gallery and a logo saves each of them on its own.
 */
final class MediaAttachments
{
    /**
     * Rewrite one role of one language in a single transaction. An empty list
     * detaches everything of that role and language, which is how a field
     * cleared in a form is saved.
     *
     * The same file named twice is attached once: a gallery showing one
     * picture in two places is a mistake of the form and not an order to
     * write two rows that would then count as two usages.
     *
     * @param  iterable<array-key, int|CmsMedia>  $media
     */
    public function replace(Model $owner, string $role, ?string $locale, iterable $media): void
    {
        $usableType = $owner->getMorphClass();
        $usableId = self::keyOf($owner);
        $path = $locale ?? '';
        $mediaIds = self::idsOf($media);

        (new CmsMediaUsage())->getConnection()->transaction(static function () use ($usableType, $usableId, $role, $path, $mediaIds): void {
            self::rows($usableType, $usableId, $role, $path)->delete();

            foreach ($mediaIds as $position => $mediaId) {
                CmsMediaUsage::query()->create([
                    'cms_media_id' => $mediaId,
                    'usable_type' => $usableType,
                    'usable_id' => $usableId,
                    'field' => $role,
                    'path' => $path,
                    'position' => $position,
                ]);
            }
        });
    }

    /**
     * Put one more file at the end of a role. A file that is already there
     * keeps the place it has: appending it again would show it twice.
     */
    public function append(Model $owner, string $role, ?string $locale, CmsMedia $media): CmsMediaUsage
    {
        $usableType = $owner->getMorphClass();
        $usableId = self::keyOf($owner);
        $path = $locale ?? '';

        $existing = self::rows($usableType, $usableId, $role, $path)
            ->where('cms_media_id', $media->id)
            ->first();

        if ($existing instanceof CmsMediaUsage) {
            return $existing;
        }

        $last = self::rows($usableType, $usableId, $role, $path)->orderByDesc('position')->first(['position']);

        /** @var CmsMediaUsage $usage */
        $usage = CmsMediaUsage::query()->create([
            'cms_media_id' => $media->id,
            'usable_type' => $usableType,
            'usable_id' => $usableId,
            'field' => $role,
            'path' => $path,
            'position' => ($last->position ?? -1) + 1,
        ]);

        return $usage;
    }

    /**
     * Take one file off a role. The positions of what is left are not
     * renumbered: they keep their order, which is all anyone reads them for,
     * and {@see self::reorder()} is where an order is actually decided.
     */
    public function detach(Model $owner, string $role, ?string $locale, CmsMedia $media): void
    {
        self::rows($owner->getMorphClass(), self::keyOf($owner), $role, $locale ?? '')
            ->where('cms_media_id', $media->id)
            ->delete();
    }

    /**
     * Let go of everything a record holds, or of one role of it in every
     * language. This is what the deleted record calls, so it takes the rows
     * of the blocks of that record too when it is given no role: a record
     * that is gone points at nothing at all.
     */
    public function release(Model $owner, ?string $role = null): void
    {
        $key = $owner->getKey();

        if ($key === null) {
            return;
        }

        $query = CmsMediaUsage::query()
            ->where('usable_type', $owner->getMorphClass())
            ->where('usable_id', $key);

        // One role is one role of the pictures: the references a block of this
        // record holds are written by {@see MediaUsageRegistrar} without a
        // position, and they are released by the record as a whole and never
        // by a role that reads like one of its columns.
        if ($role !== null) {
            $query->where('field', $role)->whereNotNull('position');
        }

        $query->delete();
    }

    /**
     * The order an editor dragged the gallery into. Identifiers that are not
     * attached to this role are ignored, and files that were left out of the
     * list keep their relative order after the ones that were named: a stale
     * screen must not silently drop a picture somebody added meanwhile.
     *
     * @param  list<int>  $mediaIdsInOrder
     */
    public function reorder(Model $owner, string $role, ?string $locale, array $mediaIdsInOrder): void
    {
        $usableType = $owner->getMorphClass();
        $usableId = self::keyOf($owner);
        $path = $locale ?? '';

        (new CmsMediaUsage())->getConnection()->transaction(static function () use ($usableType, $usableId, $role, $path, $mediaIdsInOrder): void {
            $rows = self::rows($usableType, $usableId, $role, $path)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            /** @var list<int> $ordered */
            $ordered = [];

            foreach ($mediaIdsInOrder as $mediaId) {
                foreach ($rows as $row) {
                    if ((int) $row->cms_media_id === $mediaId && ! in_array((int) $row->id, $ordered, true)) {
                        $ordered[] = (int) $row->id;
                    }
                }
            }

            foreach ($rows as $row) {
                if (! in_array((int) $row->id, $ordered, true)) {
                    $ordered[] = (int) $row->id;
                }
            }

            foreach ($ordered as $position => $id) {
                CmsMediaUsage::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * The rows of one owner, one role and one language, and nothing else.
     *
     * `position is not null` is what keeps the two writers of the table apart,
     * see {@see CmsMediaUsage::isAttachment()}: a record that also holds
     * references inside the content of its blocks has rows of
     * {@see MediaUsageRegistrar} under the same owner, and a role that happens
     * to be called like one of its columns must not take them along.
     *
     * @return Builder<CmsMediaUsage>
     */
    private static function rows(string $usableType, int|string $usableId, string $role, string $path): Builder
    {
        return CmsMediaUsage::query()
            ->where('usable_type', $usableType)
            ->where('usable_id', $usableId)
            ->where('field', $role)
            ->where('path', $path)
            ->whereNotNull('position');
    }

    /**
     * @param  iterable<array-key, int|CmsMedia>  $media
     * @return list<int>
     */
    private static function idsOf(iterable $media): array
    {
        $ids = [];

        foreach ($media as $item) {
            $id = $item instanceof CmsMedia ? $item->id : $item;

            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A record that was never saved has nothing to attach a file to, and
     * writing a row with a null key would hand those files to whoever is
     * saved next.
     */
    private static function keyOf(Model $owner): int|string
    {
        $key = $owner->getKey();

        if (! (is_int($key) || is_string($key)) || $key === '') {
            throw new InvalidArgumentException(
                'Media cannot be attached to an unsaved '.$owner::class.': the record has no key yet.'
            );
        }

        return $key;
    }
}
