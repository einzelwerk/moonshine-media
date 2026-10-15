@props([
    /* The «Медиа» section: the tree of the folders on the left, the grid in
       the middle, the details of one file on the right, the selection bar
       under them and the queue of the uploads in the corner. Everything the
       browser draws it draws out of the json of the endpoints of the section;
       the markup of a tile lives here and not in the script. */
    'endpoints' => [],
    'labels' => [],
    'locales' => [],
    'defaultLocale' => null,
    'kindChips' => [],
    'sorts' => [],
    'disks' => [],
    'singleDisk' => true,
    'defaultDisk' => null,
    'tree' => ['root' => ['count' => 0], 'folders' => []],
    'accept' => '',
    'uploadHints' => [],
])
<div
    class="cms-library"
    x-data="cmsMediaLibrary({
        token: '{{ csrf_token() }}',
        endpoints: @js($endpoints),
        labels: @js($labels),
        locales: @js($locales),
        defaultLocale: @js($defaultLocale),
        tree: @js($tree),
        disk: @js($defaultDisk),
    })"
    x-on:dragenter.window="dragEnter($event)"
    x-on:dragover.window.prevent="dragOver($event)"
    x-on:dragleave.window="dragLeave($event)"
    x-on:drop.window.prevent="dropUpload($event)"
    x-on:keydown.escape.window="onEscape()"
>
    {{-- ---------------------------------------------------------- layout --}}
    <div class="cms-library__layout" x-bind:class="details && '_has-panel'">

        {{-- ------------------------------------------------------ folders --}}
        @include('moonshine-media::components.media-folder-tree')

        {{-- --------------------------------------------------------- grid --}}
        <section class="cms-library__main">
            <div class="cms-library__toolbar">
                <label class="cms-lib-search">
                    <x-moonshine::icon icon="magnifying-glass" size="4" />
                    <input
                        type="search"
                        class="cms-lib-input"
                        x-model="q"
                        x-on:input.debounce.300ms="reload()"
                        x-bind:placeholder="labels.search"
                        x-bind:aria-label="labels.search"
                    >
                </label>

                <div class="cms-library__toolbar-tail">
                    @if(! $singleDisk)
                        <label class="cms-lib-field">
                            <span class="cms-lib-field__label">{{ __('moonshine-media::library.storage') }}</span>
                            <select class="cms-lib-select" x-model="disk">
                                @foreach($disks as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <button type="button" class="cms-lib-btn cms-lib-btn--primary" x-on:click="$refs.upload.click()">
                        <x-moonshine::icon icon="arrow-up-tray" size="4" />
                        {{ __('moonshine-media::library.upload') }}
                    </button>
                </div>
            </div>

            <div class="cms-library__filters">
                <div class="cms-lib-chips" role="group">
                    @foreach($kindChips as $chip)
                        <button
                            type="button"
                            class="cms-lib-chip"
                            x-bind:class="kind === @js($chip['value']) && '_is-active'"
                            x-bind:aria-pressed="kind === @js($chip['value'])"
                            x-on:click="setKind(@js($chip['value']))"
                        >{{ $chip['label'] }}</button>
                    @endforeach
                </div>

                <div class="cms-lib-chips" role="group">
                    <button
                        type="button"
                        class="cms-lib-chip"
                        x-bind:class="withoutAlt && '_is-active'"
                        x-bind:aria-pressed="withoutAlt"
                        x-on:click="withoutAlt = ! withoutAlt; reload()"
                    >{{ __('moonshine-media::library.flags.without_alt') }}</button>
                    <button
                        type="button"
                        class="cms-lib-chip"
                        x-bind:class="unused && '_is-active'"
                        x-bind:aria-pressed="unused"
                        x-on:click="unused = ! unused; reload()"
                    >{{ __('moonshine-media::library.flags.unused') }}</button>
                </div>

                <div class="cms-library__filters-tail">
                    <select class="cms-lib-select" x-model="sort" x-on:change="reload()" aria-label="{{ __('moonshine-media::library.sorts.label') }}">
                        @foreach($sorts as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>

                    <div class="cms-lib-view" role="group">
                        <button
                            type="button"
                            class="cms-lib-view__btn"
                            x-bind:class="view === 'grid' && '_is-active'"
                            x-bind:aria-pressed="view === 'grid'"
                            title="{{ __('moonshine-media::library.view.grid') }}"
                            x-on:click="setView('grid')"
                        ><x-moonshine::icon icon="squares-2x2" size="4" /></button>
                        <button
                            type="button"
                            class="cms-lib-view__btn"
                            x-bind:class="view === 'list' && '_is-active'"
                            x-bind:aria-pressed="view === 'list'"
                            title="{{ __('moonshine-media::library.view.list') }}"
                            x-on:click="setView('list')"
                        ><x-moonshine::icon icon="list-bullet" size="4" /></button>
                    </div>
                </div>
            </div>

            <div class="cms-library__body">
                {{-- The empty state says which of the two emptinesses it is:
                     a library with nothing in it and a search that found
                     nothing need different sentences. --}}
                <div class="cms-lib-empty" x-show="! loading && items.length === 0" x-cloak>
                    <x-moonshine::icon icon="photo" size="10" />
                    <p class="cms-lib-empty__title" x-text="filtered() ? labels.empty.search_title : labels.empty.title"></p>
                    <p class="cms-lib-empty__hint" x-text="filtered() ? labels.empty.search_hint : labels.empty.hint"></p>
                    <button type="button" class="cms-lib-btn cms-lib-btn--primary" x-show="! filtered()" x-on:click="$refs.upload.click()">
                        {{ __('moonshine-media::library.upload') }}
                    </button>
                </div>

                <div
                    class="cms-lib-grid"
                    role="listbox"
                    aria-multiselectable="true"
                    x-show="view === 'grid' && items.length > 0"
                    x-on:keydown="onGridKey($event)"
                >
                    @include('moonshine-media::components.media-tile')
                </div>

                <table class="cms-lib-list" x-show="view === 'list' && items.length > 0" x-cloak>
                    <thead>
                        <tr>
                            <th class="cms-lib-list__thumb"></th>
                            <th>{{ __('moonshine-media::library.columns.name') }}</th>
                            <th>{{ __('moonshine-media::library.columns.kind') }}</th>
                            <th>{{ __('moonshine-media::library.columns.size') }}</th>
                            <th>{{ __('moonshine-media::library.columns.date') }}</th>
                            <th>{{ __('moonshine-media::library.columns.usages') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" x-bind:key="item.id">
                            <tr
                                class="cms-lib-list__row"
                                tabindex="0"
                                draggable="true"
                                x-bind:class="{ '_is-selected': isSelected(item.id), '_is-open': details && details.id === item.id }"
                                x-on:click="openDetails(item, $event)"
                                x-on:keydown.enter="openDetails(item, $event)"
                                x-on:dragstart="startDrag(item, $event)"
                                x-on:dragend="endDrag()"
                            >
                                <td class="cms-lib-list__thumb">
                                    <template x-if="item.thumb_url">
                                        <img x-bind:src="item.thumb_url" alt="" loading="lazy">
                                    </template>
                                    <template x-if="! item.thumb_url">
                                        <span class="cms-lib-list__glyph">
                                            @include('moonshine-media::components.media-kind-icon', ['expr' => 'item.kind', 'size' => '5'])
                                        </span>
                                    </template>
                                </td>
                                <td>
                                    <span class="cms-lib-list__name" x-text="item.name"></span>
                                    <span class="cms-tile__badges">
                                        <template x-if="! item.has_alt">
                                            <span class="cms-lib-badge _is-warn" x-text="labels.badges.no_alt"></span>
                                        </template>
                                    </span>
                                </td>
                                <td x-text="item.kind_label"></td>
                                <td x-text="item.size_human"></td>
                                <td x-text="item.created_at_human"></td>
                                <td x-text="item.usages_label"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="cms-lib-more">
                    <button type="button" class="cms-lib-btn" x-show="nextCursor" x-cloak x-bind:disabled="loading" x-on:click="loadMore()">
                        {{ __('moonshine-media::library.more') }}
                    </button>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------ details --}}
        <aside class="cms-library__panel" x-show="details" x-cloak>
            <template x-if="details">
                <div
                    class="cms-panel"
                    role="region"
                    tabindex="-1"
                    x-bind:aria-label="details.name"
                >
                    <div class="cms-panel__head">
                        <span class="cms-panel__title">{{ __('moonshine-media::library.details.title') }}</span>
                        <button type="button" class="cms-panel__close" x-bind:aria-label="labels.details.close" x-on:click="closeDetails()">
                            <x-moonshine::icon icon="x-mark" size="5" />
                        </button>
                    </div>

                    <div class="cms-panel__preview" x-bind:class="details.kind === 'vector' && '_is-vector'">
                        <template x-if="details.kind === 'image'">
                            <img x-bind:src="details.url" x-bind:alt="details.name">
                        </template>
                        <template x-if="details.kind === 'vector'">
                            <img class="_is-contain" x-bind:src="details.url" x-bind:alt="details.name">
                        </template>
                        <template x-if="details.kind === 'video'">
                            <video controls preload="metadata" x-bind:src="details.url" x-bind:poster="details.thumb_url || null"></video>
                        </template>
                        <template x-if="details.kind === 'audio'">
                            <audio controls x-bind:src="details.url"></audio>
                        </template>
                        <template x-if="details.kind === 'document'">
                            <span class="cms-panel__glyph">
                                @include('moonshine-media::components.media-kind-icon', ['expr' => 'details.kind', 'size' => '10'])
                                <a class="cms-lib-link" x-bind:href="details.url" target="_blank" rel="noopener">{{ __('moonshine-media::library.details.open') }}</a>
                            </span>
                        </template>
                    </div>

                    <label class="cms-panel__field">
                        <span class="cms-panel__label">{{ __('moonshine-media::library.details.name') }}</span>
                        <input
                            type="text"
                            class="cms-lib-input"
                            x-model="details.name"
                            x-on:blur="saveDetails()"
                            x-on:keydown.enter.prevent="$event.target.blur()"
                        >
                    </label>

                    <div class="cms-panel__field" x-show="details.kind === 'image' || details.kind === 'vector'">
                        <div class="cms-panel__field-head">
                            <span class="cms-panel__label">{{ __('moonshine-media::library.details.alt') }}</span>
                            <div class="cms-panel__locales" x-show="locales.length > 1" role="group">
                                <template x-for="code in locales" x-bind:key="code">
                                    <button
                                        type="button"
                                        class="cms-panel__locale"
                                        x-bind:class="locale === code && '_is-active'"
                                        x-on:click="locale = code"
                                        x-text="code.toUpperCase()"
                                    ></button>
                                </template>
                            </div>
                        </div>
                        <input
                            type="text"
                            class="cms-lib-input"
                            x-bind:aria-label="labels.details.alt + ' ' + locale.toUpperCase()"
                            x-model="details.alt[locale]"
                            x-on:blur="saveDetails()"
                            x-on:keydown.enter.prevent="$event.target.blur()"
                        >
                        <span class="cms-panel__hint">{{ __('moonshine-media::library.details.alt_hint') }}</span>
                    </div>

                    <div class="cms-panel__field">
                        <button type="button" class="cms-panel__collapse" x-on:click="captionOpen = ! captionOpen">
                            <span x-bind:class="captionOpen && '_is-open'"><x-moonshine::icon icon="chevron-right" size="4" /></span>
                            {{ __('moonshine-media::library.details.caption') }}
                        </button>
                        <input
                            type="text"
                            class="cms-lib-input"
                            x-show="captionOpen"
                            x-cloak
                            x-bind:aria-label="labels.details.caption + ' ' + locale.toUpperCase()"
                            x-model="details.caption[locale]"
                            x-on:blur="saveDetails()"
                            x-on:keydown.enter.prevent="$event.target.blur()"
                        >
                    </div>

                    <div class="cms-panel__row">
                        <span class="cms-panel__label">{{ __('moonshine-media::library.details.folder') }}</span>
                        <span class="cms-panel__value" x-text="folderName(details.folder_id)"></span>
                        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="openMove([details.id])">
                            {{ __('moonshine-media::library.folders.move') }}
                        </button>
                    </div>

                    <div class="cms-panel__facts">
                        <div class="cms-panel__fact">
                            <span>{{ __('moonshine-media::library.details.size') }}</span>
                            <b x-text="details.size_human"></b>
                        </div>
                        <div class="cms-panel__fact" x-show="details.width && details.height">
                            <span>{{ __('moonshine-media::library.details.dimensions') }}</span>
                            <b x-text="details.width + ' × ' + details.height"></b>
                        </div>
                        <div class="cms-panel__fact">
                            <span>{{ __('moonshine-media::library.details.format') }}</span>
                            <b x-text="details.ext"></b>
                        </div>
                        <div class="cms-panel__fact">
                            <span>{{ __('moonshine-media::library.details.uploaded') }}</span>
                            <b x-text="details.created_at_human"></b>
                        </div>
                        <div class="cms-panel__fact" x-show="details.uploader">
                            <span>{{ __('moonshine-media::library.details.uploaded_by') }}</span>
                            <b x-text="details.uploader"></b>
                        </div>
                        <div class="cms-panel__fact" x-show="details.storage">
                            <span>{{ __('moonshine-media::library.details.storage') }}</span>
                            <b x-text="details.storage"></b>
                        </div>
                    </div>

                    <div class="cms-panel__usages">
                        <span class="cms-panel__label">{{ __('moonshine-media::library.details.used_in') }}</span>
                        <p class="cms-panel__hint" x-show="details.usages.length === 0">{{ __('moonshine-media::library.details.not_used') }}</p>
                        <ul x-show="details.usages.length > 0">
                            <template x-for="usage in details.usages" x-bind:key="usage.title + usage.type">
                                <li class="cms-panel__usage">
                                    <span class="cms-panel__usage-type" x-text="usage.type"></span>
                                    <template x-if="usage.url">
                                        <a class="cms-lib-link" x-bind:href="usage.url" x-text="usage.title"></a>
                                    </template>
                                    <template x-if="! usage.url">
                                        <span x-text="usage.title"></span>
                                    </template>
                                    <span class="cms-panel__usage-places" x-bind:title="usage.places" x-text="usage.places"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div class="cms-panel__actions">
                        <button type="button" class="cms-lib-btn" x-on:click="$refs.replace.click()">
                            <x-moonshine::icon icon="arrow-path" size="4" />
                            {{ __('moonshine-media::library.details.replace') }}
                        </button>
                        <a class="cms-lib-btn" x-bind:href="details.url" x-bind:download="details.name">
                            <x-moonshine::icon icon="arrow-down-tray" size="4" />
                            {{ __('moonshine-media::library.details.download') }}
                        </a>
                        <button type="button" class="cms-lib-btn cms-lib-btn--danger" x-on:click="askDelete([details.id])">
                            <x-moonshine::icon icon="trash" size="4" />
                            {{ __('moonshine-media::library.confirm.delete') }}
                        </button>
                    </div>
                </div>
            </template>
        </aside>
    </div>

    {{-- ------------------------------------------------------- selection --}}
    <div class="cms-lib-bar" x-show="selection.length > 0" x-cloak>
        <span class="cms-lib-bar__count" x-text="labels.selection.selected.replace(':count', selection.length)"></span>
        <button type="button" class="cms-lib-btn" x-on:click="openMove(selection)">{{ __('moonshine-media::library.selection.move') }}</button>
        <button type="button" class="cms-lib-btn cms-lib-btn--danger" x-on:click="askDelete(selection)">{{ __('moonshine-media::library.selection.delete') }}</button>
        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="clearSelection()">{{ __('moonshine-media::library.selection.clear') }}</button>
    </div>

    {{-- --------------------------------------------------------- uploads --}}
    @include('moonshine-media::components.media-uploader', ['accept' => $accept, 'uploadHints' => $uploadHints])

    {{-- ---------------------------------------------------------- modals --}}
    <x-moonshine::modal name="cms-media-delete" data-cms-dialog="cms-media-delete" :title="__('moonshine-media::library.confirm.delete_title')">
        <div class="cms-lib-dialog">
            <p x-text="confirmText"></p>

            <div class="cms-lib-dialog__usages" x-show="confirmUsages.length > 0" x-cloak>
                <ul>
                    <template x-for="usage in confirmUsages" x-bind:key="usage.title + usage.type">
                        <li class="cms-panel__usage">
                            <span class="cms-panel__usage-type" x-text="usage.type"></span>
                            <template x-if="usage.url">
                                <a class="cms-lib-link" x-bind:href="usage.url" x-text="usage.title"></a>
                            </template>
                            <template x-if="! usage.url">
                                <span x-text="usage.title"></span>
                            </template>
                            <span class="cms-panel__usage-places" x-text="usage.places"></span>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="cms-lib-dialog__actions">
                <button
                    type="button"
                    class="cms-lib-btn cms-lib-btn--danger"
                    x-show="confirmUsages.length === 0"
                    x-on:click="confirmDelete()"
                >{{ __('moonshine-media::library.confirm.delete') }}</button>
                <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="closeDialog('cms-media-delete')">
                    {{ __('moonshine-media::library.confirm.cancel') }}
                </button>
            </div>
        </div>
    </x-moonshine::modal>

    <x-moonshine::modal name="cms-media-move" data-cms-dialog="cms-media-move" :title="__('moonshine-media::library.move.title')">
        <div class="cms-lib-dialog">
            <ul class="cms-lib-picker">
                <li>
                    <button type="button" x-bind:class="movePick === null && '_is-active'" x-on:click="movePick = null">
                        <x-moonshine::icon icon="folder-open" size="4" />
                        {{ __('moonshine-media::library.folders.root') }}
                    </button>
                </li>
                <template x-for="node in pickerFolders()" x-bind:key="node.id">
                    <li>
                        <button
                            type="button"
                            x-bind:style="'--cms-depth: ' + node.depth"
                            x-bind:class="movePick === node.id && '_is-active'"
                            x-bind:disabled="node.disabled"
                            x-on:click="movePick = node.id"
                        >
                            <x-moonshine::icon icon="folder" size="4" />
                            <span x-text="node.name"></span>
                        </button>
                    </li>
                </template>
            </ul>

            <div class="cms-lib-dialog__actions">
                <button type="button" class="cms-lib-btn cms-lib-btn--primary" x-on:click="confirmMove()">
                    {{ __('moonshine-media::library.move.submit') }}
                </button>
                <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="closeDialog('cms-media-move')">
                    {{ __('moonshine-media::library.move.cancel') }}
                </button>
            </div>
        </div>
    </x-moonshine::modal>
</div>
