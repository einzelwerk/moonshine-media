<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Queries;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Exceptions\InvalidMediaLocaleException;
use Ewk\Cms\Media\Models\CmsMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Reading the library.
 *
 * Every method returns a copy, so a builder may be handed around and narrowed
 * twice without the first caller finding its own filters changed underneath.
 *
 * Two decisions run through all of it. The count of usages is asked for with
 * `withCount` and the relation is never loaded: the grid needs the number and
 * not the rows, and loading them would be one query per page of a library of
 * thousands. And paging is by cursor and not by offset: an offset shifts
 * every time somebody uploads a file while the editor scrolls.
 */
final class MediaQuery
{
    public const int DEFAULT_LIMIT = 60;

    public const int MAX_LIMIT = 200;

    /**
     * The key {@see self::countsByFolder()} counts the root under. Folder
     * identifiers start at one, so nothing can collide with it; a literal
     * `null` cannot be an array key in PHP at all.
     */
    public const int ROOT = 0;

    /**
     * The escape character of the `LIKE` patterns. `!` and not `\`, because a
     * backslash inside a string literal means one thing to MariaDB and
     * another to sqlite, while `!` is a plain character to both.
     */
    private const string LIKE_ESCAPE = '!';

    private ?string $search = null;

    private ?int $folderId = null;

    private bool $rootOnly = false;

    /** @var list<FileKind> */
    private array $kinds = [];

    private ?string $withoutAltLocale = null;

    private bool $unused = false;

    private ?string $ownerType = null;

    private int|string|null $ownerId = null;

    private ?string $attachedType = null;

    private int|string|null $attachedId = null;

    private ?string $attachedRole = null;

    private MediaSort $sort = MediaSort::NEWEST;

    private ?string $cursor = null;

    private int $limit = self::DEFAULT_LIMIT;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Free text over the name the editor gave, the name the file arrived
     * with and the metadata of the record.
     *
     * The last of the three is a `LIKE` over the raw json and not a json
     * query: `whereJsonContains` cannot do a substring, and the json path
     * functions differ enough between MariaDB and sqlite to be worth avoiding
     * for a search box. Three prices come with that. A term also matches a
     * caption, a title or, in theory, a language code, which for finding a
     * picture by its description is a wider net and not a wrong one. A term
     * outside ASCII is searched for twice, because the cast writes the column
     * with `\uXXXX` escapes; the escaped form is compared as it is written, so
     * «Афиша» and «афиша» are two different searches there. And the column is
     * `longtext` with `utf8mb4_bin` on MariaDB, where `name` and
     * `original_name` are `utf8mb4_unicode_ci`, so on that database the
     * description part of the search matches the case that was typed, which in
     * sqlite it does not.
     */
    public function search(?string $term): self
    {
        $clone = clone $this;
        $term = $term === null ? null : trim($term);
        $clone->search = ($term === null || $term === '') ? null : $term;

        return $clone;
    }

    /**
     * `rootOnly` is not the same as «no folder chosen»: the first lists the
     * files that lie outside every folder, the second lists the whole library.
     */
    public function inFolder(?int $folderId, bool $rootOnly = false): self
    {
        $clone = clone $this;
        $clone->folderId = $folderId;
        $clone->rootOnly = $rootOnly;

        return $clone;
    }

    /**
     * @param  FileKind|list<FileKind>|null  $kind
     */
    public function ofKind(FileKind|array|null $kind): self
    {
        $clone = clone $this;
        $clone->kinds = $kind === null ? [] : (is_array($kind) ? $kind : [$kind]);

        return $clone;
    }

    /**
     * What an editor fixes before a release: pictures without a description
     * in the language they are working in.
     *
     * Only an image and a vector are asked about. A video, a sound file and a
     * document are not described by `alt`, and listing them here would turn a
     * working list into a list nobody can ever finish.
     */
    public function withoutAlt(string $locale): self
    {
        InvalidMediaLocaleException::assert($locale);

        $clone = clone $this;
        $clone->withoutAltLocale = $locale;

        return $clone;
    }

    public function unused(): self
    {
        $clone = clone $this;
        $clone->unused = true;

        return $clone;
    }

    public function ownedBy(?string $type, int|string|null $id): self
    {
        $clone = clone $this;
        $clone->ownerType = $type;
        $clone->ownerId = $id;

        return $clone;
    }

    /**
     * The files a record owns: the gallery of one venue, every picture of one
     * tour whatever the role.
     *
     * An owner and not a pair of columns, because the row stores the morph
     * alias of the model and the caller should not have to know that.
     */
    public function attachedTo(Model $owner, ?string $role = null): self
    {
        $key = $owner->getKey();

        if (! (is_int($key) || is_string($key)) || $key === '') {
            throw new InvalidArgumentException(
                'An unsaved '.$owner::class.' owns no files: the record has no key yet.'
            );
        }

        $clone = clone $this;
        $clone->attachedType = $owner->getMorphClass();
        $clone->attachedId = $key;
        $clone->attachedRole = $role;

        return $clone;
    }

    public function sort(MediaSort $sort): self
    {
        $clone = clone $this;
        $clone->sort = $sort;

        return $clone;
    }

    public function after(?string $cursor): self
    {
        $clone = clone $this;
        $clone->cursor = $cursor;

        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(1, min($limit, self::MAX_LIMIT));

        return $clone;
    }

    public function get(): MediaPage
    {
        $limit = $this->limit;

        /** @var list<CmsMedia> $rows */
        $rows = $this->toBuilder()->limit($limit + 1)->get()->all();

        // One row more than asked for is how the listing knows there is a
        // next page, without counting anything.
        if (count($rows) <= $limit) {
            return new MediaPage($rows);
        }

        $items = array_slice($rows, 0, $limit);
        $last = $items[$limit - 1];

        return new MediaPage($items, MediaCursor::encode($this->sort->keyOf($last), $last->id));
    }

    /**
     * The narrowed builder, for whoever needs something the page does not
     * give: a count for a badge, a pluck of identifiers for a bulk action.
     *
     * @return Builder<CmsMedia>
     */
    public function toBuilder(): Builder
    {
        $query = CmsMedia::query()->withCount('usages');

        $this->applySearch($query);
        $this->applyFolder($query);
        $this->applyKinds($query);
        $this->applyWithoutAlt($query);
        $this->applyOwner($query);
        $this->applyAttachedTo($query);

        // Attachments are usages like the references of a block, so a picture
        // of a tour is not «unused» either.
        if ($this->unused) {
            $query->whereDoesntHave('usages');
        }

        $this->applyOrder($query);
        $this->applyCursor($query);

        return $query;
    }

    public static function find(string $uuid): ?CmsMedia
    {
        return CmsMedia::query()->where('uuid', $uuid)->first();
    }

    public static function findOrFail(string $uuid): CmsMedia
    {
        $media = self::find($uuid);

        if (! $media instanceof CmsMedia) {
            throw (new ModelNotFoundException())->setModel(CmsMedia::class, [$uuid]);
        }

        return $media;
    }

    /**
     * How many files each folder of the tree holds, for the badges beside it.
     * One grouped query and not one per folder: the tree is drawn on every
     * screen of the library.
     *
     * The root is counted under {@see self::ROOT}.
     *
     * @return array<int, int>  folder id => count
     */
    public static function countsByFolder(): array
    {
        $counts = [];

        $rows = CmsMedia::query()
            ->selectRaw('folder_id, count(*) as records')
            ->groupBy('folder_id')
            ->get();

        foreach ($rows as $row) {
            $folderId = $row->getAttribute('folder_id');
            $records = $row->getAttribute('records');
            $counts[is_numeric($folderId) ? (int) $folderId : self::ROOT] = is_numeric($records) ? (int) $records : 0;
        }

        return $counts;
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applySearch(Builder $query): void
    {
        if ($this->search === null) {
            return;
        }

        $pattern = '%'.self::escapeLike($this->search).'%';
        $jsonPattern = self::jsonPattern($this->search);
        $escape = self::LIKE_ESCAPE;

        $query->where(static function (Builder $inner) use ($pattern, $jsonPattern, $escape): void {
            $inner->whereRaw("name like ? escape '{$escape}'", [$pattern])
                ->orWhereRaw("original_name like ? escape '{$escape}'", [$pattern])
                ->orWhereRaw("metadata like ? escape '{$escape}'", [$pattern]);

            if ($jsonPattern !== null) {
                $inner->orWhereRaw("metadata like ? escape '{$escape}'", [$jsonPattern]);
            }
        });
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyFolder(Builder $query): void
    {
        if ($this->rootOnly) {
            $query->whereNull('folder_id');

            return;
        }

        if ($this->folderId !== null) {
            $query->where('folder_id', $this->folderId);
        }
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyKinds(Builder $query): void
    {
        if ($this->kinds === []) {
            return;
        }

        $query->whereIn('kind', array_map(static fn (FileKind $kind): string => $kind->value, $this->kinds));
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyWithoutAlt(Builder $query): void
    {
        if ($this->withoutAltLocale === null) {
            return;
        }

        $path = 'metadata->alt->'.$this->withoutAltLocale;

        $query
            ->whereIn('kind', [FileKind::IMAGE->value, FileKind::VECTOR->value])
            ->where(static function (Builder $inner) use ($path): void {
                $inner->whereNull($path)->orWhere($path, '=', '');
            });
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyOwner(Builder $query): void
    {
        if ($this->ownerType === null || $this->ownerId === null) {
            return;
        }

        $query->where('owner_type', $this->ownerType)->where('owner_id', $this->ownerId);
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyAttachedTo(Builder $query): void
    {
        if ($this->attachedType === null) {
            return;
        }

        $usableType = $this->attachedType;
        $usableId = $this->attachedId;
        $role = $this->attachedRole;

        $query->whereHas('usages', static function (Builder $usages) use ($usableType, $usableId, $role): void {
            // Attachments and not every usage of that record: a reference
            // inside the content of a block is written without a position and
            // is not a file the record owns.
            $usages->where('usable_type', $usableType)
                ->where('usable_id', $usableId)
                ->whereNotNull('position');

            if ($role !== null) {
                $usages->where('field', $role);
            }
        });
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyOrder(Builder $query): void
    {
        $direction = $this->sort->direction();

        $query->orderByRaw($this->sort->expression().' '.$direction)->orderBy('id', $direction);
    }

    /**
     * @param  Builder<CmsMedia>  $query
     */
    private function applyCursor(Builder $query): void
    {
        $cursor = MediaCursor::decode($this->cursor);

        if ($cursor === null) {
            return;
        }

        $expression = $this->sort->expression();
        $operator = $this->sort->direction() === 'asc' ? '>' : '<';
        $key = $cursor['key'];
        $id = $cursor['id'];

        $query->where(static function (Builder $inner) use ($expression, $operator, $key, $id): void {
            $inner
                ->whereRaw("{$expression} {$operator} ?", [$key])
                ->orWhere(static function (Builder $tie) use ($expression, $operator, $key, $id): void {
                    $tie->whereRaw("{$expression} = ?", [$key])->where('id', $operator, $id);
                });
        });
    }

    /**
     * The same term written the way `json_encode` writes it into the column,
     * or null when the two are the same string. Every character of ASCII is
     * written as itself, so this only ever comes into play for a search in a
     * language that is not English.
     */
    private static function jsonPattern(string $term): ?string
    {
        $encoded = trim((string) json_encode($term), '"');

        return $encoded === $term ? null : '%'.self::escapeLike($encoded).'%';
    }

    /**
     * The wildcards of `LIKE` are characters a person types: a file called
     * «100%» has to be findable by its name and not match everything.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );
    }
}
