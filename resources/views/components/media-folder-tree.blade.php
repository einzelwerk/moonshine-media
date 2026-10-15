{{-- The tree of the folders on the left of the library.

     A partial and not markup of the page: the picker dialog draws the same
     tree over the same Alpine component, and a second copy of it is how the
     two screens would drift apart. Everything it
     reads — `tree`, `folder`, `expanded`, `renaming` — belongs to the
     component it stands inside.

     `manage` is the one difference between the two: the section creates,
     renames, moves and deletes folders, and the picker only walks through
     them. --}}
@props(['manage' => true])
        <aside class="cms-library__side">
            @if($manage)
            <div class="cms-library__side-head">
                <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="startCreateFolder(null)">
                    <x-moonshine::icon icon="plus" size="4" />
                    {{ __('moonshine-media::library.folders.create') }}
                </button>
            </div>

            <form class="cms-folder-new" x-show="creating !== false" x-cloak x-on:submit.prevent="createFolder()">
                <input
                    type="text"
                    class="cms-lib-input"
                    x-ref="newFolder"
                    x-model="creatingName"
                    x-on:keydown.escape.stop="creating = false"
                    placeholder="{{ __('moonshine-media::library.folders.name') }}"
                >
                <button type="submit" class="cms-lib-btn cms-lib-btn--primary">{{ __('moonshine-media::library.folders.save') }}</button>
                <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="creating = false">{{ __('moonshine-media::library.folders.cancel') }}</button>
            </form>
            @endif

            <ul class="cms-tree" role="tree">
                <li class="cms-tree__row" role="treeitem" x-bind:aria-selected="folder === null">
                    <div
                        class="cms-tree__item"
                        x-bind:class="{ '_is-current': folder === null, '_is-drop': dropFolder === 0 }"
                        x-on:dragover.prevent="dropFolder = 0"
                        x-on:dragleave="dropFolder === 0 && (dropFolder = null)"
                        x-on:drop.prevent.stop="dropOnFolder(null)"
                    >
                        <span class="cms-tree__twist"></span>
                        <button type="button" class="cms-tree__name" x-on:click="selectFolder(null)">
                            <x-moonshine::icon icon="folder-open" size="4" />
                            <span>{{ __('moonshine-media::library.folders.root') }}</span>
                        </button>
                        <span class="cms-tree__count" x-text="tree.root.count"></span>
                        {{-- The root has no menu of its own, and the counts of
                             the tree have to stand in one column all the same. --}}
                        <span class="cms-tree__menu cms-tree__menu--empty"></span>
                    </div>
                </li>

                <template x-for="node in visibleFolders()" x-bind:key="node.id">
                    <li class="cms-tree__row" role="treeitem" x-bind:aria-selected="folder === node.id">
                        <div
                            class="cms-tree__item"
                            x-bind:style="'--cms-depth: ' + node.depth"
                            x-bind:class="{ '_is-current': folder === node.id, '_is-drop': dropFolder === node.id }"
                            x-on:dragover.prevent="dropFolder = node.id"
                            x-on:dragleave="dropFolder === node.id && (dropFolder = null)"
                            x-on:drop.prevent.stop="dropOnFolder(node.id)"
                        >
                            <button
                                type="button"
                                class="cms-tree__twist"
                                x-bind:class="node.children.length === 0 && '_is-empty'"
                                x-bind:aria-label="labels.folders.expand"
                                {{-- A folder with nothing under it has nothing
                                     to open, so it is not a stop on the way
                                     through the tree with Tab. --}}
                                x-bind:tabindex="node.children.length === 0 ? -1 : 0"
                                x-bind:aria-hidden="node.children.length === 0"
                                x-on:click="toggleFolder(node.id)"
                            >
                                <template x-if="node.children.length > 0">
                                    <span x-bind:class="expanded[node.id] ? '_is-open' : ''">
                                        <x-moonshine::icon icon="chevron-right" size="4" />
                                    </span>
                                </template>
                            </button>

                            <template x-if="renaming !== node.id">
                                <button type="button" class="cms-tree__name" x-on:click="selectFolder(node.id)">
                                    <x-moonshine::icon icon="folder" size="4" />
                                    <span x-text="node.name"></span>
                                </button>
                            </template>

                            <template x-if="{{ $manage ? 'renaming === node.id' : 'false' }}">
                                <form class="cms-tree__rename" x-on:submit.prevent="renameFolder(node)">
                                    <input
                                        type="text"
                                        class="cms-lib-input"
                                        x-ref="rename"
                                        x-model="renamingName"
                                        x-on:keydown.escape.stop="renaming = null"
                                        x-on:blur="renameFolder(node)"
                                    >
                                </form>
                            </template>

                            <span class="cms-tree__count" x-text="node.count"></span>

                            @if($manage)
                            <div class="cms-tree__menu" x-data="{ open: false }" x-on:click.outside="open = false">
                                <button
                                    type="button"
                                    class="cms-tree__more"
                                    x-bind:aria-label="labels.folders.actions"
                                    x-on:click.stop="open = ! open"
                                >
                                    <x-moonshine::icon icon="ellipsis-horizontal" size="4" />
                                </button>

                                <div class="cms-tree__dropdown" x-show="open" x-cloak x-on:click="open = false">
                                    <button type="button" x-on:click="startRenameFolder(node)">{{ __('moonshine-media::library.folders.rename') }}</button>
                                    <button type="button" x-on:click="openFolderMove(node)">{{ __('moonshine-media::library.folders.move') }}</button>
                                    <button
                                        type="button"
                                        class="_is-danger"
                                        x-bind:disabled="! node.empty"
                                        x-bind:title="node.empty ? '' : labels.folders.not_empty"
                                        x-on:click="node.empty && deleteFolder(node)"
                                    >{{ __('moonshine-media::library.folders.delete') }}</button>
                                </div>
                            </div>
                            @else
                            {{-- The counts of the tree stand in one column,
                                 and the menu of the section is the width of
                                 that column. --}}
                            <span class="cms-tree__menu cms-tree__menu--empty"></span>
                            @endif
                        </div>
                    </li>
                </template>
            </ul>
        </aside>
