<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Tests\Fixtures;

use Ewk\Cms\Media\Concerns\HasMediaAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The same owner with soft deletes, which is the one case where a record that
 * fired `deleted` must keep its pictures: it is coming back.
 *
 * @property int $id
 * @property string|null $title
 */
final class SoftAttachmentOwner extends Model
{
    use HasMediaAttachments;
    use SoftDeletes;

    public const string MORPH_ALIAS = 'test_soft_attachment_owner';

    public $timestamps = false;

    protected $table = 'test_attachment_owners';

    protected $fillable = ['title'];
}
