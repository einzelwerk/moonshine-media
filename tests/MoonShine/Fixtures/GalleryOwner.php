<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\MoonShine\Fixtures;

use Ewk\Cms\Media\Concerns\HasMediaAttachments;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that owns pictures, standing in for a tour or a venue: a logo with
 * no language, a gallery, and a cover per language.
 *
 * The package ships no such model and never will: every owner of pictures is
 * a model of the installation, and the field knows it by the trait alone.
 *
 * @property int $id
 * @property string|null $title
 * @property array<string, string>|null $name
 */
final class GalleryOwner extends Model
{
    use HasMediaAttachments;

    public const string MORPH_ALIAS = 'test_gallery_owner';

    public $timestamps = false;

    protected $table = 'test_gallery_owners';

    protected $fillable = ['title', 'name'];

    /** @var array<string, string> */
    protected $casts = ['name' => 'array'];
}
