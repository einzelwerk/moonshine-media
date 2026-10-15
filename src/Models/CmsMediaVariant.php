<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Models;

use Ewk\Cms\Media\Enums\MediaVariantState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cms_media_id
 * @property string $preset
 * @property string $disk
 * @property string|null $path
 * @property int|null $width
 * @property int|null $height
 * @property int|null $size
 * @property string|null $format
 * @property MediaVariantState $state
 * @property-read CmsMedia $cmsMedia
 */
final class CmsMediaVariant extends Model
{
    /** @see CmsMedia::MORPH_ALIAS */
    public const string MORPH_ALIAS = 'cms_media_variant';

    protected $fillable = [
        'cms_media_id',
        'preset',
        'disk',
        'path',
        'width',
        'height',
        'size',
        'format',
        'state',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'state' => MediaVariantState::class,
    ];

    /**
     * @return BelongsTo<CmsMedia, $this>
     */
    public function cmsMedia(): BelongsTo
    {
        return $this->belongsTo(CmsMedia::class);
    }
}
