<?php

declare(strict_types=1);

use Ewk\Cms\Media\Models\CmsMediaUsage;

it('tells an attachment from a reference inside a block by its position', function (): void {
    $attachment = new CmsMediaUsage(['field' => 'cover', 'path' => 'ru', 'position' => 0]);
    $block = new CmsMediaUsage(['field' => 'content', 'path' => 'items.0.image']);

    expect($attachment->isAttachment())->toBeTrue()
        ->and($block->isAttachment())->toBeFalse();
});

it('reads the language of an attachment and nothing for a role that has none', function (): void {
    expect((new CmsMediaUsage(['path' => 'ru']))->locale())->toBe('ru')
        ->and((new CmsMediaUsage(['path' => '']))->locale())->toBeNull();
});
