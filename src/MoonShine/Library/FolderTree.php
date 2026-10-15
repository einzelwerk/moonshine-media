<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\MoonShine\Library;

use Ewk\Cms\Media\Models\CmsMediaFolder;
use Ewk\Cms\Media\Queries\MediaQuery;

/**
 * The tree on the left of the library.
 *
 * The root is «Все файлы» and not a folder: choosing it lists the whole
 * library, folders and all, which is what an editor looking for a file they
 * filed somewhere expects. The count beside it is therefore the whole
 * library, and the count beside a folder is what lies directly in it.
 *
 * The whole tree travels in one answer. A library has tens of folders and not
 * thousands, and a tree that loads a level at a time cannot be drawn open at
 * the folder the editor was last in.
 */
final readonly class FolderTree
{
    /**
     * @return array{root: array{count: int}, folders: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $counts = MediaQuery::countsByFolder();

        /** @var array<int, list<CmsMediaFolder>> $byParent */
        $byParent = [];

        foreach (CmsMediaFolder::query()->orderBy('name')->get() as $folder) {
            $byParent[$folder->parent_id ?? 0][] = $folder;
        }

        return [
            'root' => ['count' => array_sum($counts)],
            'folders' => $this->level($byParent, 0, $counts),
        ];
    }

    /**
     * @param  array<int, list<CmsMediaFolder>>  $byParent
     * @param  array<int, int>  $counts
     * @return list<array<string, mixed>>
     */
    private function level(array $byParent, int $parent, array $counts): array
    {
        $nodes = [];

        foreach ($byParent[$parent] ?? [] as $folder) {
            $children = $this->level($byParent, $folder->id, $counts);

            $nodes[] = [
                'id' => $folder->id,
                'parent_id' => $folder->parent_id,
                'name' => $folder->name,
                'count' => $counts[$folder->id] ?? 0,
                // Whether the «Удалить» of the menu is offered at all: a
                // folder with anything in it is never deleted, and saying so
                // before the click is kinder than a refusal after it.
                'empty' => ($counts[$folder->id] ?? 0) === 0 && $children === [],
                'children' => $children,
            ];
        }

        return $nodes;
    }
}
