<?php

declare(strict_types=1);

use Ewk\Cms\Media\Models\CmsMediaUsage;
use Ewk\Cms\Media\Tests\Fixtures\LibraryFactory;
use Ewk\Cms\Media\Usages\MediaUsages;
use Ewk\Cms\Media\Usages\UsageLabelRegistry;
use Ewk\Cms\Media\Usages\UsageLabelResolver;
use Ewk\Cms\Media\Usages\UsageOwnerLabel;

/**
 * A resolver that names one kind of owner out of a table of titles, which is
 * all the registry asks of the packages that register real ones.
 *
 * @param  array<int|string, string>  $titles
 */
function titleResolver(string $usableType, array $titles, string $type = 'page'): UsageLabelResolver
{
    return new class ($usableType, $titles, $type) implements UsageLabelResolver {
        /**
         * @param  array<int|string, string>  $titles
         */
        public function __construct(
            private readonly string $usableType,
            private readonly array $titles,
            private readonly string $type,
        ) {
        }

        public function supports(string $usableType): bool
        {
            return $usableType === $this->usableType;
        }

        public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
        {
            $title = $this->titles[$usage->usable_id] ?? null;

            return $title === null ? null : new UsageOwnerLabel(
                title: $title,
                url: null,
                type: $this->type,
                ownerType: $this->usableType,
                ownerId: $usage->usable_id,
            );
        }
    };
}

it('groups the rows by the record they belong to and lists the places inside it', function (): void {
    app(UsageLabelRegistry::class)->register(titleResolver('cms_block', [1 => 'Главная', 2 => 'Афиша']));

    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'items.0.image');
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'items.3.image');
    LibraryFactory::usage($media, 'cms_block', 2, 'content', 'background');

    $groups = app(MediaUsages::class)->of($media);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]->owner->title)->toBe('Афиша')
        ->and($groups[0]->fields())->toBe(['background'])
        ->and($groups[1]->owner->title)->toBe('Главная')
        ->and($groups[1]->fields())->toBe(['items.0.image', 'items.3.image'])
        ->and($groups[1]->sources)->toHaveCount(1)
        ->and($groups[1]->sources[0]->type)->toBe('cms_block')
        ->and($groups[1]->sources[0]->id)->toBe(1)
        ->and($groups[1]->sources[0]->fields)->toBe(['items.0.image', 'items.3.image'])
        ->and($groups[1]->owner->ownerType)->toBe('cms_block')
        ->and($groups[1]->owner->ownerId)->toBe(1)
        ->and($groups[1]->owner->type)->toBe('page')
        ->and($groups[1]->owner->url)->toBeNull();
});

it('names a record nobody registered a resolver for, instead of hiding it', function (): void {
    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_unknown', 12, 'content', 'image');

    $groups = app(MediaUsages::class)->of($media);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->owner->title)->toBe('cms_unknown #12')
        ->and($groups[0]->owner->type)->toBe('cms_unknown')
        ->and($groups[0]->owner->url)->toBeNull();
});

it('falls back when a resolver that supports the kind cannot name the row', function (): void {
    app(UsageLabelRegistry::class)->register(titleResolver('cms_block', []));

    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_block', 5, 'content', 'image');

    expect(app(MediaUsages::class)->of($media)[0]->owner->title)->toBe('cms_block #5');
});

it('says nothing about a file nothing points at', function (): void {
    expect(app(MediaUsages::class)->of(LibraryFactory::media()))->toBe([]);
});

it('names a record no usage points at directly', function (): void {
    $registry = app(UsageLabelRegistry::class);
    $registry->register(titleResolver('cms_page', [4 => 'Контакты']));

    expect($registry->for('cms_page', 4)->title)->toBe('Контакты')
        ->and($registry->for('cms_page', 9)->title)->toBe('cms_page #9');
});

it('stops a chain of owners that points back at itself', function (): void {
    $registry = app(UsageLabelRegistry::class);

    $registry->register(new class ($registry) implements UsageLabelResolver {
        public function __construct(private readonly UsageLabelRegistry $registry)
        {
        }

        public function supports(string $usableType): bool
        {
            return $usableType === 'cms_loop';
        }

        public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
        {
            return $this->registry->for('cms_loop', $usage->usable_id);
        }
    });

    expect($registry->for('cms_loop', 1)->title)->toBe('cms_loop #1');
});

it('puts two records that answer to one owner into a single group', function (): void {
    // Two blocks of one page: the page is one place to open, and the panel
    // still needs to know which of its blocks to scroll to.
    app(UsageLabelRegistry::class)->register(new class () implements UsageLabelResolver {
        public function supports(string $usableType): bool
        {
            return $usableType === 'cms_block';
        }

        public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
        {
            return new UsageOwnerLabel('Главная', null, 'page', 'cms_page', 7);
        }
    });

    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'cover');
    LibraryFactory::usage($media, 'cms_block', 2, 'content', 'items.0.image');
    LibraryFactory::usage($media, 'cms_block', 2, 'content', 'items.1.image');

    $groups = app(MediaUsages::class)->of($media);

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->owner->ownerType)->toBe('cms_page')
        ->and($groups[0]->owner->ownerId)->toBe(7)
        ->and($groups[0]->sources)->toHaveCount(2)
        ->and($groups[0]->sources[0]->id)->toBe(1)
        ->and($groups[0]->sources[0]->fields)->toBe(['cover'])
        ->and($groups[0]->sources[1]->id)->toBe(2)
        ->and($groups[0]->sources[1]->fields)->toBe(['items.0.image', 'items.1.image'])
        ->and($groups[0]->fields())->toBe(['cover', 'items.0.image', 'items.1.image']);
});

it('keeps a row nothing could name on its own, under its own key', function (): void {
    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_unknown', 1, 'content', 'image');
    LibraryFactory::usage($media, 'cms_unknown', 2, 'content', 'image');

    $groups = app(MediaUsages::class)->of($media);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]->sources[0]->id)->toBe(1)
        ->and($groups[1]->sources[0]->id)->toBe(2);
});

it('asks about each record once for the whole listing', function (): void {
    $asked = 0;

    app(UsageLabelRegistry::class)->register(new class ($asked) implements UsageLabelResolver {
        public function __construct(private int &$asked)
        {
        }

        public function supports(string $usableType): bool
        {
            return $usableType === 'cms_block';
        }

        public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
        {
            $this->asked++;

            return new UsageOwnerLabel('Главная', null, 'page', 'cms_page', 7);
        }
    });

    $media = LibraryFactory::media();

    for ($i = 0; $i < 10; $i++) {
        LibraryFactory::usage($media, 'cms_block', 1, 'content', 'items.'.$i.'.image');
        LibraryFactory::usage($media, 'cms_block', 2, 'content', 'items.'.$i.'.image');
    }

    app(MediaUsages::class)->of($media);

    // Twenty rows, two records: two questions.
    expect($asked)->toBe(2);
});

it('forgets the names it cached as soon as the listing is built', function (): void {
    $registry = app(UsageLabelRegistry::class);
    $titles = ['Первое'];

    $registry->register(new class ($titles) implements UsageLabelResolver {
        /** @param list<string> $titles */
        public function __construct(private array &$titles)
        {
        }

        public function supports(string $usableType): bool
        {
            return $usableType === 'cms_block';
        }

        public function resolve(CmsMediaUsage $usage): ?UsageOwnerLabel
        {
            return new UsageOwnerLabel($this->titles[0], null, 'page', 'cms_block', $usage->usable_id);
        }
    });

    $media = LibraryFactory::media();
    LibraryFactory::usage($media, 'cms_block', 1, 'content', 'cover');

    expect(app(MediaUsages::class)->of($media)[0]->owner->title)->toBe('Первое');

    $titles[0] = 'Второе';

    // A memo that outlived the call would answer «Первое» here, which under
    // Octane means for the life of the worker.
    expect(app(MediaUsages::class)->of($media)[0]->owner->title)->toBe('Второе')
        ->and($registry->for('cms_block', 1)->title)->toBe('Второе');
});
