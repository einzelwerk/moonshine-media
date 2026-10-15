@props([
    /* The body of the picker dialog: the tree of the folders on the left, the
       grid in the middle, the queue of the uploads under them and the two
       buttons that end the choosing.

       It is the section again and not a copy of it: the tree, the tiles and
       the queue are the very same partials, over the very same Alpine core.
       What the dialog leaves out is everything that is not choosing — no
       details panel, no renaming, no moving, no deleting — and what it adds is
       «Выбрать». */
    'endpoints' => [],
    'labels' => [],
    'locales' => [],
    'defaultLocale' => null,
    'kindChips' => [],
    'allowedKinds' => [],
    'sorts' => [],
    'disks' => [],
    'singleDisk' => true,
    'defaultDisk' => null,
    'tree' => ['root' => ['count' => 0], 'folders' => []],
    'accept' => '',
    'uploadHints' => [],
    'multiple' => false,
    'target' => '',
    'scope' => '',
    'selected' => null,
    'folder' => null,
    'libraryUrl' => null,
])
<div
    class="cms-library cms-picker"
    data-cms-media-picker="{{ $target }}"
    x-data="cmsMediaPicker({
        token: '{{ csrf_token() }}',
        endpoints: @js($endpoints),
        labels: @js($labels),
        locales: @js($locales),
        defaultLocale: @js($defaultLocale),
        tree: @js($tree),
        disk: @js($defaultDisk),
        allowedKinds: @js(array_values($allowedKinds)),
        multiple: @js((bool) $multiple),
        target: @js($target),
        scope: @js($scope),
        startFolder: @js($folder),
        startSelected: @js($selected),
        modal: @js(\Ewk\Cms\Media\MoonShine\Library\PickerModal::NAME),
    })"
    {{-- Scoped to the dialog and never to the window: the body of a modal
         stays in the document once it has been loaded, and a window handler
         would take a file dropped on the page long after the dialog closed. --}}
    x-on:dragenter="dragEnter($event)"
    x-on:dragover.prevent="dragOver($event)"
    x-on:dragleave="dragLeave($event)"
    x-on:drop.prevent="dropUpload($event)"
    {{-- The overlay stack of the panel closes the dialog on Escape on its own;
         this only tells the field, so the keyboard comes back to the button
         that opened it. --}}
    x-on:keydown.escape="onEscape()"
>
    <div class="cms-picker__layout">
        {{-- ---------------------------------------------------- folders --}}
        <div class="cms-picker__side" x-bind:class="treeOpen && '_is-open'">
            @include('moonshine-media::components.media-folder-tree', ['manage' => false])
        </div>

        {{-- ------------------------------------------------------- grid --}}
        <section class="cms-library__main cms-picker__main">
            <div class="cms-library__toolbar">
                <button
                    type="button"
                    class="cms-lib-btn cms-lib-btn--ghost cms-picker__folders"
                    x-bind:aria-expanded="treeOpen"
                    x-on:click="treeOpen = ! treeOpen"
                >
                    <x-moonshine::icon icon="folder" size="4" />
                    <span x-text="folderName(folder)"></span>
                </button>

                <label class="cms-lib-search">
                    <x-moonshine::icon icon="magnifying-glass" size="4" />
                    <input
                        type="search"
                        class="cms-lib-input"
                        x-model="q"
                        x-on:input.debounce.300ms="reload()"
                        {{-- Opened from a form, this input may stand inside
                             it: Enter here searches and never submits the form
                             behind the dialog. --}}
                        x-on:keydown.enter.prevent="reload()"
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

                    <select class="cms-lib-select" x-model="sort" x-on:change="reload()" aria-label="{{ __('moonshine-media::library.sorts.label') }}">
                        @foreach($sorts as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>

                    <button type="button" class="cms-lib-btn cms-lib-btn--primary" x-on:click="$refs.upload.click()">
                        <x-moonshine::icon icon="arrow-up-tray" size="4" />
                        {{ __('moonshine-media::library.upload') }}
                    </button>
                </div>
            </div>

            {{-- A field that takes one kind of file has nothing to filter by:
                 the row of chips is then the word «Все» beside itself. --}}
            @if(count($kindChips) > 2)
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
                </div>
            @endif

            <div class="cms-library__body cms-picker__body">
                <div class="cms-lib-empty" x-show="! loading && items.length === 0" x-cloak>
                    <x-moonshine::icon icon="photo" size="10" />
                    <p class="cms-lib-empty__title" x-text="filtered() ? labels.empty.search_title : labels.empty.title"></p>
                    <p class="cms-lib-empty__hint" x-text="filtered() ? labels.empty.search_hint : labels.picker.empty_hint"></p>
                    <button type="button" class="cms-lib-btn cms-lib-btn--primary" x-show="! filtered()" x-on:click="$refs.upload.click()">
                        {{ __('moonshine-media::library.upload') }}
                    </button>
                </div>

                <div
                    class="cms-lib-grid"
                    role="listbox"
                    x-bind:aria-multiselectable="multiple"
                    x-show="items.length > 0"
                    x-on:keydown="onGridKey($event)"
                >
                    @include('moonshine-media::components.media-tile')
                </div>

                <div class="cms-lib-more">
                    <button type="button" class="cms-lib-btn" x-show="nextCursor" x-cloak x-bind:disabled="loading" x-on:click="loadMore()">
                        {{ __('moonshine-media::library.more') }}
                    </button>
                </div>
            </div>
        </section>
    </div>

    {{-- -------------------------------------------------------- uploads --}}
    @include('moonshine-media::components.media-uploader', ['accept' => $accept, 'uploadHints' => $uploadHints])

    {{-- --------------------------------------------------------- footer --}}
    <div class="cms-picker__foot">
        @if($libraryUrl !== null)
            <a class="cms-lib-link cms-picker__away" href="{{ $libraryUrl }}" target="_blank" rel="noopener">
                {{ __('moonshine-media::library.picker.open_library') }}
            </a>
        @endif

        <span class="cms-picker__count" x-show="multiple && selection.length > 0" x-cloak
              x-text="labels.selection.selected.replace(':count', selection.length)"></span>

        <button
            type="button"
            class="cms-lib-btn cms-lib-btn--primary"
            x-bind:disabled="selection.length === 0"
            x-on:click="pick()"
        >{{ __('moonshine-media::library.picker.submit') }}</button>

        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-on:click="cancel()">
            {{ __('moonshine-media::library.picker.cancel') }}
        </button>
    </div>
</div>
