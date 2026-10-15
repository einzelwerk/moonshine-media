<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $cms_media_id
 * @property string $usable_type
 * @property int|string $usable_id
 * @property string $field
 * @property string $path
 * @property int|null $position
 */
final class CmsMediaUsage extends Model
{
    public $timestamps = false;

    /** @see CmsMedia::MORPH_ALIAS */
    public const string MORPH_ALIAS = 'cms_media_usage';

    protected $fillable = [
        'cms_media_id',
        'usable_type',
        'usable_id',
        'field',
        'path',
        'position',
    ];

    /**
     * @var array<string, string>
     *
     * Ordered attachments of the second stage fill it; a block leaves it
     * empty, because the order of a block is the path inside its content.
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * A picture a record owns, as opposed to a reference inside the content of
     * a block. The position is what tells the two apart: an attachment always
     * carries one, because a gallery has an order, and a block never does,
     * because its order is the path.
     */
    public function isAttachment(): bool
    {
        return $this->position !== null;
    }

    /**
     * The language of an attachment, or null for a role that has none. A
     * picture of a tour is a different file in every language, and the row
     * keeps that language where a block keeps the path inside its content, so
     * this says nothing useful about a row that is not an attachment.
     */
    public function locale(): ?string
    {
        return $this->path === '' ? null : $this->path;
    }

    /**
     * @return BelongsTo<CmsMedia, $this>
     */
    public function cmsMedia(): BelongsTo
    {
        return $this->belongsTo(CmsMedia::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }
}
