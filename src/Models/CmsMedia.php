<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Models;

use Ewk\Cms\Media\Enums\FileKind;
use Ewk\Cms\Media\Enums\MediaVariantState;
use Ewk\Cms\Media\Exceptions\MediaInUseException;
use Ewk\Cms\Media\Support\PresetRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum
 * @property string|null $placeholder
 * @property array<string, mixed>|null $metadata
 * @property string|null $name
 * @property FileKind $kind
 * @property int|null $folder_id
 * @property int $version
 * @property int|null $uploaded_by
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property-read CmsMediaFolder|null $folder
 * @property-read Model|null $owner
 * @property-read Collection<int, CmsMediaVariant> $variants
 * @property-read Collection<int, CmsMediaUsage> $usages
 */
final class CmsMedia extends Model
{
    /**
     * What the polymorphic columns of the library store for a file. An alias
     * instead of the class name keeps the rows readable and survives a
     * renaming of the model.
     */
    public const string MORPH_ALIAS = 'cms_media';

    protected $fillable = [
        'uuid',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'width',
        'height',
        'checksum',
        'placeholder',
        'metadata',
        'name',
        'kind',
        'folder_id',
        'version',
        'uploaded_by',
        'owner_type',
        'owner_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'kind' => FileKind::class,
        'version' => 'integer',
        'uploaded_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (CmsMedia $media): void {
            $media->uuid = $media->uuid ?: (string) Str::uuid();

            // The editor renames a file in the details panel, but the grid has
            // to show something readable before anyone opens it. The client
            // name without its extension is that something; the extension is
            // dropped because it is a technical detail the panel spells out
            // separately.
            if (($media->name ?? '') === '' && ($media->original_name ?? '') !== '') {
                $name = pathinfo((string) $media->original_name, PATHINFO_FILENAME);

                if ($name !== '') {
                    $media->name = $name;
                }
            }
        });

        static::deleting(function (CmsMedia $media): void {
            $usages = $media->usages()->count();

            if ($usages > 0) {
                throw MediaInUseException::forMedia($media, $usages);
            }
        });
    }

    /**
     * @return HasMany<CmsMediaVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(CmsMediaVariant::class);
    }

    /**
     * @return HasMany<CmsMediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CmsMediaUsage::class);
    }

    /**
     * @return BelongsTo<CmsMediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(CmsMediaFolder::class, 'folder_id');
    }

    /**
     * Empty for the files of administrators. It is filled when someone other
     * than the panel uploads.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The whole of the filter that the cabinet API applies: a file of another
     * owner must not merely be refused, it must not be found, so this narrows
     * the query instead of checking a record that was already loaded.
     *
     * @param  Builder<CmsMedia>  $query
     */
    public function scopeOwnedBy(Builder $query, string $type, int|string $id): void
    {
        $query->where('owner_type', $type)->where('owner_id', $id);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Resolve the public URL for a named variant, falling back to the nearest
     * ready variant and finally the original when the variant is not ready yet.
     * Never returns a broken address.
     */
    public function resolvedVariantUrl(string $preset): string
    {
        $variant = $this->variants->firstWhere('preset', $preset);

        if ($variant instanceof CmsMediaVariant && $variant->state === MediaVariantState::READY && $variant->path !== null) {
            return Storage::disk($variant->disk)->url($variant->path);
        }

        $targetWidth = $this->targetWidth($preset);

        $nearest = $this->variants
            ->where('state', MediaVariantState::READY)
            ->whereNotNull('path')
            ->sortBy(fn (CmsMediaVariant $candidate): int => abs(($candidate->width ?? 0) - $targetWidth))
            ->first();

        if ($nearest instanceof CmsMediaVariant && $nearest->path !== null) {
            return Storage::disk($nearest->disk)->url($nearest->path);
        }

        return $this->url();
    }

    /**
     * A picture of the file for a place that draws an `<img>`: the variant,
     * the nearest ready one, and the original only where the original is a
     * picture itself. A video whose frame is not cut yet, or a document, has
     * none: the address of the file itself in an `<img>` is a broken picture.
     */
    public function previewUrl(string $preset): ?string
    {
        if ($this->kind === FileKind::IMAGE || $this->kind === FileKind::VECTOR) {
            return $this->resolvedVariantUrl($preset);
        }

        $ready = $this->variants->contains(
            fn (CmsMediaVariant $variant): bool => $variant->state === MediaVariantState::READY && $variant->path !== null,
        );

        return $ready ? $this->resolvedVariantUrl($preset) : null;
    }

    public function isPending(): bool
    {
        return $this->variants->contains(
            fn (CmsMediaVariant $variant): bool => $variant->state === MediaVariantState::PENDING
        );
    }

    private function targetWidth(string $preset): int
    {
        $registry = app(PresetRegistry::class);

        return $registry->has($preset) ? $registry->get($preset)->width : (int) ($this->width ?? 0);
    }
}
