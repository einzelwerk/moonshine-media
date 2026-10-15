<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\DeleteMedia;
use Ewk\Cms\Media\Exceptions\MediaInUseException;
use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Queries\MediaQuery;
use Ewk\Cms\Media\Services\MediaAttachments;
use Ewk\Cms\Media\Services\MediaUsageRegistrar;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Tests\Fixtures\AttachmentOwner;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Ewk\Cms\Media\Tests\Fixtures\SoftAttachmentOwner;
use Ewk\Cms\Media\Usages\MediaUsages;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table of the owner lives here and not in the migrations of the package:
 * a record that owns pictures belongs to the installation, and the library
 * knows it by its morph alias alone.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('test_attachment_owners')) {
        Schema::create('test_attachment_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->softDeletes();
        });
    }

    Relation::morphMap([
        AttachmentOwner::MORPH_ALIAS => AttachmentOwner::class,
        SoftAttachmentOwner::MORPH_ALIAS => SoftAttachmentOwner::class,
    ]);
});

function attachmentOwner(string $title = 'Venue'): AttachmentOwner
{
    /** @var AttachmentOwner $owner */
    $owner = AttachmentOwner::query()->create(['title' => $title]);

    return $owner;
}

it('writes the rows of a role in the order they were given', function (): void {
    $owner = attachmentOwner();
    $first = LibraryFactory::media();
    $second = LibraryFactory::media();

    // A record and an identifier are both accepted: a form hands over
    // identifiers, a seeder hands over the records it just made.
    app(MediaAttachments::class)->replace($owner, 'gallery', null, [$first, $second->id]);

    $rows = CmsMediaUsage::query()->orderBy('position')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->usable_type)->toBe(AttachmentOwner::MORPH_ALIAS)
        ->and((int) $rows[0]->usable_id)->toBe($owner->id)
        ->and($rows[0]->field)->toBe('gallery')
        ->and($rows[0]->path)->toBe('')
        ->and($rows[0]->position)->toBe(0)
        ->and($rows[0]->cms_media_id)->toBe($first->id)
        ->and($rows[1]->position)->toBe(1)
        ->and($rows[1]->cms_media_id)->toBe($second->id)
        ->and($rows[0]->isAttachment())->toBeTrue()
        ->and($rows[0]->locale())->toBeNull();
});

it('attaches a file named twice only once', function (): void {
    $owner = attachmentOwner();
    $media = LibraryFactory::media();

    app(MediaAttachments::class)->replace($owner, 'gallery', null, [$media->id, $media->id]);

    expect(CmsMediaUsage::query()->count())->toBe(1);
});

it('rewrites one role and one language, leaving the rest where they are', function (): void {
    $owner = attachmentOwner();
    $ru = LibraryFactory::media();
    $en = LibraryFactory::media();
    $logo = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [$ru]);
    $attachments->replace($owner, 'cover', 'en', [$en]);
    $attachments->replace($owner, 'logo', null, [$logo]);

    $replacement = LibraryFactory::media();
    $attachments->replace($owner, 'cover', 'ru', [$replacement]);

    expect($owner->attachedMedia('cover', 'ru')->pluck('id')->all())->toBe([$replacement->id])
        ->and($owner->attachedMedia('cover', 'en')->pluck('id')->all())->toBe([$en->id])
        ->and($owner->attachedMedia('logo')->pluck('id')->all())->toBe([$logo->id]);
});

it('detaches everything of a role and a language when the list is empty', function (): void {
    $owner = attachmentOwner();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [LibraryFactory::media()]);
    $attachments->replace($owner, 'cover', 'en', [LibraryFactory::media()]);
    $attachments->replace($owner, 'cover', 'ru', []);

    expect($owner->attachedMedia('cover', 'ru'))->toHaveCount(0)
        ->and($owner->attachedMedia('cover', 'en'))->toHaveCount(1);
});

it('answers with nothing for a language that has nothing, instead of another one', function (): void {
    // The fallback of languages is the application's decision: a library
    // that guessed would put a Russian poster on an English page without
    // anyone asking.
    $owner = attachmentOwner();
    app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [LibraryFactory::media()]);

    expect($owner->firstAttachedMedia('cover', 'en'))->toBeNull()
        ->and($owner->firstAttachedMedia('cover'))->toBeNull()
        ->and($owner->firstAttachedMedia('cover', 'ru'))->toBeInstanceOf(CmsMedia::class);
});

it('reads one role in every language at once', function (): void {
    $owner = attachmentOwner();
    $ru = LibraryFactory::media();
    $en = LibraryFactory::media();
    $shared = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [$ru]);
    $attachments->replace($owner, 'cover', 'en', [$en]);
    $attachments->replace($owner, 'cover', null, [$shared]);

    $byLocale = $owner->attachedMediaByLocale('cover');
    ksort($byLocale);

    expect(array_keys($byLocale))->toBe(['', 'en', 'ru'])
        ->and($byLocale['ru']->pluck('id')->all())->toBe([$ru->id])
        ->and($byLocale['en']->pluck('id')->all())->toBe([$en->id])
        ->and($byLocale['']->pluck('id')->all())->toBe([$shared->id]);
});

it('appends at the end and never twice', function (): void {
    $owner = attachmentOwner();
    $first = LibraryFactory::media();
    $second = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $one = $attachments->append($owner, 'gallery', null, $first);
    $two = $attachments->append($owner, 'gallery', null, $second);
    $again = $attachments->append($owner, 'gallery', null, $first);

    expect($one->position)->toBe(0)
        ->and($two->position)->toBe(1)
        ->and($again->id)->toBe($one->id)
        ->and(CmsMediaUsage::query()->count())->toBe(2)
        ->and($owner->attachedMedia('gallery')->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('takes one file off a role and leaves the order of the rest', function (): void {
    $owner = attachmentOwner();
    $first = LibraryFactory::media();
    $second = LibraryFactory::media();
    $third = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'gallery', null, [$first, $second, $third]);
    $attachments->detach($owner, 'gallery', null, $second);

    expect($owner->attachedMedia('gallery')->pluck('id')->all())->toBe([$first->id, $third->id]);
});

it('writes the order an editor dragged the gallery into', function (): void {
    $owner = attachmentOwner();
    $first = LibraryFactory::media();
    $second = LibraryFactory::media();
    $third = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'gallery', null, [$first, $second, $third]);

    // A stale screen names two of the three and an identifier that is not
    // attached at all: the third keeps its place at the end, the stranger is
    // ignored.
    $attachments->reorder($owner, 'gallery', null, [$third->id, $first->id, 9999]);

    expect($owner->attachedMedia('gallery')->pluck('id')->all())
        ->toBe([$third->id, $first->id, $second->id])
        ->and(CmsMediaUsage::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

it('lets a record go of one role or of everything it holds', function (): void {
    $owner = attachmentOwner();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [LibraryFactory::media()]);
    $attachments->replace($owner, 'cover', 'en', [LibraryFactory::media()]);
    $attachments->replace($owner, 'gallery', null, [LibraryFactory::media()]);

    $attachments->release($owner, 'cover');

    expect(CmsMediaUsage::query()->count())->toBe(1);

    $attachments->release($owner);

    expect(CmsMediaUsage::query()->count())->toBe(0);
});

it('releases the rows of a record that was deleted', function (): void {
    $owner = attachmentOwner();
    app(MediaAttachments::class)->replace($owner, 'gallery', null, [LibraryFactory::media()]);

    $owner->delete();

    expect(CmsMediaUsage::query()->count())->toBe(0);
});

it('keeps the pictures of a record that was only soft deleted', function (): void {
    /** @var SoftAttachmentOwner $owner */
    $owner = SoftAttachmentOwner::query()->create(['title' => 'Tour']);
    app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [LibraryFactory::media()]);

    $owner->delete();

    expect(CmsMediaUsage::query()->count())->toBe(1);

    $owner->forceDelete();

    expect(CmsMediaUsage::query()->count())->toBe(0);
});

it('refuses to attach a file to a record that was never saved', function (): void {
    app(MediaAttachments::class)->replace(new AttachmentOwner(), 'cover', null, [LibraryFactory::media()]);
})->throws(InvalidArgumentException::class);

it('draws a listing of records with their pictures in four queries', function (): void {
    foreach (['Первая', 'Вторая', 'Третья'] as $title) {
        $owner = attachmentOwner($title);
        $media = LibraryFactory::media();
        LibraryFactory::variant($media);
        app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [$media]);
    }

    DB::connection()->enableQueryLog();

    $covers = [];

    foreach (AttachmentOwner::query()->withAttachedMedia(['cover'])->get() as $owner) {
        $cover = $owner->firstAttachedMedia('cover', 'ru');
        $covers[] = $cover?->resolvedVariantUrl('thumb');
    }

    $queries = count(DB::connection()->getQueryLog());

    // The records, their rows, the files behind them and the variants of
    // those files: four, whether there are three records or three hundred.
    expect($queries)->toBe(4)
        ->and($covers)->toHaveCount(3)
        ->and($covers[0])->toBeString();
});

it('refuses to delete a file that a record still owns', function (): void {
    $owner = attachmentOwner();
    $media = LibraryFactory::media();
    app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [$media]);

    expect(fn () => app(DeleteMedia::class)->handle($media, Actor::admin()))
        ->toThrow(MediaInUseException::class);
});

it('names the record that owns a file, with the role and the language', function (): void {
    $owner = attachmentOwner();
    $media = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [$media]);
    $attachments->replace($owner, 'logo', null, [$media]);

    $groups = app(MediaUsages::class)->of($media);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->owner->title)->toBe(AttachmentOwner::MORPH_ALIAS.' #'.$owner->id)
        // The role and not the language alone: «ru» tells an editor nothing
        // about where the picture is shown.
        ->and($groups[0]->fields())->toBe(['cover:ru', 'logo']);
});

it('lists the files one record owns', function (): void {
    $owner = attachmentOwner('Первая');
    $other = attachmentOwner('Вторая');
    $cover = LibraryFactory::media();
    $gallery = LibraryFactory::media();
    $stranger = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [$cover]);
    $attachments->replace($owner, 'gallery', null, [$gallery]);
    $attachments->replace($other, 'cover', 'ru', [$stranger]);

    $all = MediaQuery::make()->attachedTo($owner)->get()->items;
    $covers = MediaQuery::make()->attachedTo($owner, 'cover')->get()->items;

    expect(array_map(static fn (CmsMedia $item): int => $item->id, $all))
        ->toEqualCanonicalizing([$cover->id, $gallery->id])
        ->and(array_map(static fn (CmsMedia $item): int => $item->id, $covers))->toBe([$cover->id]);
});

it('does not call a file unused while a record owns it', function (): void {
    $owner = attachmentOwner();
    $attached = LibraryFactory::media();
    $free = LibraryFactory::media();

    app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [$attached]);

    $unused = MediaQuery::make()->unused()->get()->items;

    expect(array_map(static fn (CmsMedia $item): int => $item->id, $unused))->toBe([$free->id]);
});

/**
 * A record can hold both kinds of row: the pictures it owns and the references
 * inside the content of its blocks, and the second kind is written with the
 * column as the `field`. A role named like one of those columns must not take
 * them along, in either direction.
 */
it('leaves the references of a block alone while a role is written and read', function (): void {
    $owner = attachmentOwner();
    $attached = LibraryFactory::media();
    $inBlock = LibraryFactory::media();
    $attachments = app(MediaAttachments::class);

    $attachments->replace($owner, 'cover', 'ru', [$attached]);
    app(MediaUsageRegistrar::class)->sync(
        AttachmentOwner::MORPH_ALIAS,
        $owner->id,
        'cover',
        ['items.0.image' => $inBlock->id],
    );

    $blockRow = CmsMediaUsage::query()->whereNull('position')->firstOrFail();

    // Reading a role never sees it, and neither does the listing of the files
    // the record owns.
    expect($owner->attachedMedia('cover', 'ru')->pluck('id')->all())->toBe([$attached->id])
        ->and($owner->mediaAttachments()->count())->toBe(1)
        ->and(array_map(
            static fn (CmsMedia $item): int => $item->id,
            MediaQuery::make()->attachedTo($owner)->get()->items,
        ))->toBe([$attached->id]);

    // Rewriting the role and letting the role go both leave it where it is.
    $attachments->replace($owner, 'cover', 'ru', [LibraryFactory::media()]);
    $attachments->release($owner, 'cover');

    expect(CmsMediaUsage::query()->whereKey($blockRow->id)->exists())->toBeTrue()
        ->and(CmsMediaUsage::query()->count())->toBe(1);

    // And the registrar, writing that same column again, leaves the pictures
    // of the record where they are.
    $attachments->replace($owner, 'cover', 'ru', [$attached]);
    app(MediaUsageRegistrar::class)->sync(
        AttachmentOwner::MORPH_ALIAS,
        $owner->id,
        'cover',
        ['items.1.image' => $inBlock->id],
    );

    expect($owner->attachedMedia('cover', 'ru')->pluck('id')->all())->toBe([$attached->id])
        ->and(CmsMediaUsage::query()->whereNull('position')->count())->toBe(1);

    // A record that is gone points at nothing at all, blocks included.
    $owner->delete();

    expect(CmsMediaUsage::query()->count())->toBe(0);
});

it('shows both kinds of row in «where is this file used»', function (): void {
    $owner = attachmentOwner();
    $media = LibraryFactory::media();

    app(MediaAttachments::class)->replace($owner, 'cover', 'ru', [$media]);
    app(MediaUsageRegistrar::class)->sync(
        AttachmentOwner::MORPH_ALIAS,
        $owner->id,
        'content',
        ['items.0.image' => $media->id],
    );

    expect(app(MediaUsages::class)->of($media)[0]->fields())->toBe(['cover:ru', 'items.0.image']);
});
