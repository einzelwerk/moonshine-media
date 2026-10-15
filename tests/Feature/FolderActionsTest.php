<?php

declare(strict_types=1);

use Ewk\Cms\Media\Actions\CreateFolder;
use Ewk\Cms\Media\Actions\DeleteFolder;
use Ewk\Cms\Media\Actions\MoveFolder;
use Ewk\Cms\Media\Actions\RenameFolder;
use Ewk\Cms\Media\Exceptions\DuplicateFolderNameException;
use Ewk\Cms\Media\Exceptions\FolderNotEmptyException;
use Ewk\Cms\Media\Exceptions\ForbiddenMediaActionException;
use Ewk\Cms\Media\Exceptions\InvalidFolderMoveException;
use Ewk\Cms\Media\Exceptions\InvalidMediaNameException;
use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Support\Actor;
use Ewk\Cms\Media\Support\FolderSiblings;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Illuminate\Database\QueryException;

it('creates a folder at the root and under a parent', function (): void {
    $root = app(CreateFolder::class)->handle(null, '  Afisha  ', Actor::admin());
    $child = app(CreateFolder::class)->handle($root, 'Events', Actor::admin());

    expect($root->name)->toBe('Afisha')
        ->and($root->parent_id)->toBeNull()
        ->and($child->parent_id)->toBe($root->id);
});

it('refuses a duplicate at the root, where the unique index stays silent', function (): void {
    app(CreateFolder::class)->handle(null, 'Afisha', Actor::admin());

    // Both MariaDB and sqlite count NULL parents as distinct, so this is the
    // action's refusal and not the schema's, see CmsMediaFolderTest.
    expect(fn () => app(CreateFolder::class)->handle(null, 'Afisha', Actor::admin()))
        ->toThrow(fn (DuplicateFolderNameException $exception) => expect($exception->status())->toBe(409));

    // And folded, because MariaDB folds it in the index and sqlite does not.
    expect(fn () => app(CreateFolder::class)->handle(null, 'AFISHA', Actor::admin()))
        ->toThrow(DuplicateFolderNameException::class);

    expect(CmsMediaFolder::query()->count())->toBe(1);
});

it('refuses a duplicate under one parent and allows the name under another', function (): void {
    $one = app(CreateFolder::class)->handle(null, 'One', Actor::admin());
    $two = app(CreateFolder::class)->handle(null, 'Two', Actor::admin());

    app(CreateFolder::class)->handle($one, 'Events', Actor::admin());

    expect(fn () => app(CreateFolder::class)->handle($one, 'Events', Actor::admin()))
        ->toThrow(DuplicateFolderNameException::class);

    expect(app(CreateFolder::class)->handle($two, 'Events', Actor::admin())->exists)->toBeTrue();
});

it('refuses an empty name', function (): void {
    expect(fn () => app(CreateFolder::class)->handle(null, '   ', Actor::admin()))
        ->toThrow(fn (InvalidMediaNameException $exception) => expect($exception->status())->toBe(422));
});

it('renames a folder and leaves its own name free', function (): void {
    $root = app(CreateFolder::class)->handle(null, 'Afisha', Actor::admin());

    expect(app(RenameFolder::class)->handle($root, 'Afisha', Actor::admin())->name)->toBe('Afisha')
        ->and(app(RenameFolder::class)->handle($root, 'Posters', Actor::admin())->fresh()->name)->toBe('Posters');

    app(CreateFolder::class)->handle(null, 'Archive', Actor::admin());

    expect(fn () => app(RenameFolder::class)->handle($root, 'Archive', Actor::admin()))
        ->toThrow(DuplicateFolderNameException::class);
});

it('refuses to move a folder into itself or into its own subtree', function (): void {
    $root = LibraryFactory::folder('Site');
    $branch = LibraryFactory::folder('Events', $root);
    $leaf = LibraryFactory::folder('2026', $branch);

    foreach ([$root, $branch, $leaf] as $target) {
        try {
            app(MoveFolder::class)->handle($root, $target, Actor::admin());
            $this->fail('The move should have been refused.');
        } catch (InvalidFolderMoveException $exception) {
            expect($exception->status())->toBe(422);
        }
    }

    expect($root->fresh()->parent_id)->toBeNull();
});

it('moves a branch and back to the root', function (): void {
    $archive = LibraryFactory::folder('Archive');
    $branch = LibraryFactory::folder('Events');

    app(MoveFolder::class)->handle($branch, $archive, Actor::admin());
    expect($branch->fresh()->parent_id)->toBe($archive->id);

    app(MoveFolder::class)->handle($branch, null, Actor::admin());
    expect($branch->fresh()->parent_id)->toBeNull();
});

it('refuses a move into a folder that already holds that name', function (): void {
    $archive = LibraryFactory::folder('Archive');
    LibraryFactory::folder('Events', $archive);
    $branch = LibraryFactory::folder('Events');

    expect(fn () => app(MoveFolder::class)->handle($branch, $archive, Actor::admin()))
        ->toThrow(DuplicateFolderNameException::class);
});

it('deletes only an empty folder', function (): void {
    $parent = LibraryFactory::folder('Site');
    $child = LibraryFactory::folder('Events', $parent);

    try {
        app(DeleteFolder::class)->handle($parent, Actor::admin());
        $this->fail('The deletion should have been refused.');
    } catch (FolderNotEmptyException $exception) {
        expect($exception->status())->toBe(409);
    }

    LibraryFactory::media(['folder_id' => $child->id]);

    expect(fn () => app(DeleteFolder::class)->handle($child, Actor::admin()))
        ->toThrow(FolderNotEmptyException::class);

    $empty = LibraryFactory::folder('Empty');
    app(DeleteFolder::class)->handle($empty, Actor::admin());

    expect(CmsMediaFolder::query()->whereKey($empty->id)->exists())->toBeFalse();
});

it('keeps the tree an administrator matter in this version', function (): void {
    $owner = Actor::owner('organizer', 7);
    $folder = LibraryFactory::folder('Afisha');

    expect(fn () => app(CreateFolder::class)->handle(null, 'Mine', $owner))
        ->toThrow(ForbiddenMediaActionException::class)
        ->and(fn () => app(RenameFolder::class)->handle($folder, 'Mine', $owner))
        ->toThrow(ForbiddenMediaActionException::class)
        ->and(fn () => app(MoveFolder::class)->handle($folder, null, $owner))
        ->toThrow(ForbiddenMediaActionException::class)
        ->and(fn () => app(DeleteFolder::class)->handle($folder, $owner))
        ->toThrow(ForbiddenMediaActionException::class);
});

it('turns a violation of the unique index into the sentence an editor reads', function (): void {
    $parent = LibraryFactory::folder('Site');
    LibraryFactory::folder('Events', $parent);

    // The pre-check of the action folds case only, while the index of
    // MariaDB folds accents too, so «Cafe» beside «Café» reaches the
    // database. Writing an exact duplicate straight through the model is how
    // that case is reproduced on sqlite: the pre-check is skipped and the
    // index is what answers.
    $write = static fn (): CmsMediaFolder => CmsMediaFolder::query()->create([
        'parent_id' => $parent->id,
        'name' => 'Events',
    ]);

    expect($write)->toThrow(QueryException::class);

    try {
        FolderSiblings::guardUnique('Events', $write);
        $this->fail('The duplicate should have been refused.');
    } catch (DuplicateFolderNameException $exception) {
        expect($exception->status())->toBe(409)
            // The query error stays inside it: that is what a developer reads
            // in a log, and what an editor never sees.
            ->and($exception->getPrevious())->toBeInstanceOf(QueryException::class);
    }
});

it('lets any other fault of the database travel as it is', function (): void {
    expect(fn () => FolderSiblings::guardUnique('Events', static fn (): CmsMediaFolder => CmsMediaFolder::query()->create([
        'parent_id' => 4242,
        'name' => 'Events',
    ])))->toThrow(QueryException::class);
});
