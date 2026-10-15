<?php

declare(strict_types=1);

use Ewk\Cms\Media\Models\CmsMedia;
use Ewk\Cms\Media\Models\CmsMediaFolder;

function folder(string $name, ?CmsMediaFolder $parent = null): CmsMediaFolder
{
    return CmsMediaFolder::query()->create([
        'name' => $name,
        'parent_id' => $parent?->id,
    ]);
}

function fileIn(?CmsMediaFolder $folder, string $path = 'ab/file.jpg'): CmsMedia
{
    return CmsMedia::query()->create([
        'disk' => 'public',
        'path' => $path,
        'mime' => 'image/jpeg',
        'size' => 10,
        'folder_id' => $folder?->id,
    ]);
}

it('walks the parents root first, the way the breadcrumbs are drawn', function (): void {
    $root = folder('Site');
    $branch = folder('Events', $root);
    $leaf = folder('2026', $branch);

    expect($leaf->ancestors()->pluck('name')->all())->toBe(['Site', 'Events'])
        ->and($root->ancestors())->toBeEmpty();
});

it('calls a folder empty only while it holds neither files nor folders', function (): void {
    $parent = folder('Site');
    expect($parent->isEmpty())->toBeTrue();

    $child = folder('Events', $parent);
    expect($parent->fresh()->isEmpty())->toBeFalse()
        ->and($child->isEmpty())->toBeTrue();

    fileIn($child);
    expect($child->fresh()->isEmpty())->toBeFalse();
});

it('knows its own subtree, so a folder cannot be moved into it', function (): void {
    $root = folder('Site');
    $branch = folder('Events', $root);
    $leaf = folder('2026', $branch);
    $other = folder('Archive');

    expect($leaf->isDescendantOf($root))->toBeTrue()
        ->and($leaf->isDescendantOf($branch))->toBeTrue()
        ->and($root->isDescendantOf($leaf))->toBeFalse()
        ->and($leaf->isDescendantOf($other))->toBeFalse()
        // A folder is not inside itself: moving it onto its own place is a
        // different refusal than moving it into its own subtree.
        ->and($root->isDescendantOf($root))->toBeFalse();
});

it('orders the children by name and not by the order they were created', function (): void {
    $root = folder('Site');
    folder('Venues', $root);
    folder('Archive', $root);
    folder('Events', $root);

    expect($root->children()->pluck('name')->all())->toBe(['Archive', 'Events', 'Venues']);
});

it('empties the folder of a file instead of deleting the file with the folder', function (): void {
    $target = folder('Events');
    $media = fileIn($target);

    $target->delete();

    expect($media->fresh())->not->toBeNull()
        ->and($media->fresh()->folder_id)->toBeNull();
});
