<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\Fixtures;

use Ewk\Cms\Media\Concerns\HasMediaAttachments;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that owns pictures, standing in for a tour, a venue or an artist.
 *
 * The package ships no owner of its own: every one of them is a model of the
 * installation, and the whole point of the trait is that it knows nothing
 * about them beyond the morph alias they are registered under.
 *
 * @property int $id
 * @property string|null $title
 */
final class AttachmentOwner extends Model
{
    use HasMediaAttachments;

    public const string MORPH_ALIAS = 'test_attachment_owner';

    public $timestamps = false;

    protected $table = 'test_attachment_owners';

    protected $fillable = ['title'];
}
