@props([
    'name' => '',
    'presenceName' => '',
    'items' => [],
    'multiple' => false,
    'max' => null,
    'kinds' => [],
    'accept' => '',
    'modal' => 'cms-media-picker',
    'modalMarkup' => '',
    'endpoints' => [],
    'scope' => '',
])
@php
    $labels = [
        'uploading' => (string) __('moonshine-media::media.uploading'),
        'duplicate' => (string) __('moonshine-media::media.duplicate'),
        'failed' => (string) __('moonshine-media::library.messages.request_failed'),
        'limit' => (string) __('moonshine-media::media.attachments.limit', ['max' => $max ?? 0]),
        // The name of the file goes into the name of its button: five buttons
        // all called «Убрать» say nothing to a screen reader.
        'remove_named' => (string) __('moonshine-media::media.attachments.remove_named'),
    ];
@endphp
<div
    class="cms-attachments"
    @class(['cms-attachments--single' => ! $multiple])
    data-cms-attachments
    data-validation-wrapper
    data-validation-field="{{ $name }}"
    x-data="cmsMediaAttachments({
        items: @js($items),
        name: @js($name),
        multiple: @js((bool) $multiple),
        max: @js($max),
        kinds: @js(array_values($kinds)),
        modal: @js($modal),
        scope: @js($scope),
        endpoints: @js($endpoints),
        labels: @js($labels),
        token: '{{ csrf_token() }}',
    })"
    x-on:cms-media-pick.window="chosen($event)"
    {{-- Whatever ends the dialog — a file taken, «Отмена», Esc — the keyboard
         comes back to the button that opened it. --}}
    x-on:cms-media-close.window="closed($event)"
>
    {{-- The role was on the form. Without it an empty gallery and a role no
         form touches look the same in the request, and they mean opposite
         things, see {@see \Ewk\Cms\Media\MoonShine\Fields\MediaAttachmentsField}. --}}
    <input type="hidden" name="{{ $presenceName }}" value="1">

    {{-- What the record holds right now, written by the server: a browser
         whose script never ran still submits the state unchanged instead of
         silently emptying the gallery. Alpine takes the list over the moment
         it starts and removes this one. --}}
    <div x-ref="initial" data-cms-attachments-initial hidden>
        @foreach($items as $item)
            <input type="hidden" name="{{ $name }}[]" value="{{ $item['id'] }}" data-cms-counts>
        @endforeach
    </div>

    <div class="cms-attachments__list" x-show="items.length > 0" x-cloak>
        <template x-for="(item, index) in items" :key="item.id">
            <div
                class="cms-attachments__item"
                x-bind:class="{ 'cms-attachments__item--dragged': dragged === index, 'cms-attachments__item--over': over === index }"
                x-bind:draggable="multiple ? 'true' : 'false'"
                x-on:dragstart="startDrag(index, $event)"
                x-on:dragover.prevent="dragOver(index)"
                x-on:drop.prevent="drop(index)"
                x-on:dragend="endDrag()"
            >
                {{-- The ordered list the form submits. One input per file, in
                     the order they are shown, so the order of a gallery is the
                     order of the inputs and nothing else. --}}
                <input type="hidden" x-bind:name="name + '[]'" x-bind:value="item.id" data-cms-counts>

                <span class="cms-attachments__handle" x-show="multiple" aria-hidden="true">
                    <x-moonshine::icon icon="bars-3" size="4" />
                </span>

                <span class="cms-attachments__thumb">
                    <img x-bind:src="item.thumb_url" x-show="item.thumb_url" alt="" x-cloak>
                    <span class="cms-attachments__glyph" x-show="! item.thumb_url" x-cloak>
                        {{ __('moonshine-media::media.attachments.no_preview') }}
                    </span>
                </span>

                <span class="cms-attachments__meta">
                    <span class="cms-attachments__name" x-text="item.name"></span>
                    <span class="cms-attachments__size" x-text="item.size_human"></span>
                </span>

                <button
                    type="button"
                    class="cms-attachments__remove btn btn-secondary"
                    x-bind:aria-label="removeLabel(item)"
                    x-on:click="remove(index)"
                >{{ __('moonshine-media::media.attachments.remove') }}</button>
            </div>
        </template>
    </div>

    <p class="cms-attachments__empty" x-show="items.length === 0" x-cloak>
        {{ $multiple
            ? __('moonshine-media::media.attachments.empty_multiple')
            : __('moonshine-media::media.attachments.empty') }}
    </p>

    <div class="cms-attachments__actions">
        <button
            type="button"
            class="btn btn-secondary"
            data-cms-media-open
            x-bind:disabled="busy || full()"
            x-on:click="openLibrary()"
        >{{ __('moonshine-media::media.attachments.add') }}</button>

        <button
            type="button"
            class="btn btn-secondary"
            x-bind:disabled="busy || full()"
            x-on:click="upload()"
        >{{ __('moonshine-media::media.upload') }}</button>

        <span class="cms-attachments__hint" x-show="multiple && items.length > 1" x-cloak>
            {{ __('moonshine-media::media.attachments.reorder') }}
        </span>
    </div>

    {{-- How far the file has gone up. The bar and not a spinner: a picture of
         eight megabytes on a hotel connection is a minute of waiting. --}}
    <div class="cms-attachments__progress" x-show="busy" x-cloak>
        <i x-bind:style="'width: ' + progress + '%'"></i>
    </div>

    {{-- The same question the library asks: the bytes are already stored, so
         either that record is what they meant or they want a second copy. --}}
    <div class="cms-attachments__duplicate" x-show="duplicate" x-cloak>
        <span x-text="labels.duplicate"></span>
        <button type="button" class="btn btn-secondary" x-on:click="useExisting()">
            {{ __('moonshine-media::media.use_existing') }}
        </button>
        <button type="button" class="btn btn-secondary" x-on:click="uploadCopy()">
            {{ __('moonshine-media::media.upload_copy') }}
        </button>
    </div>

    <p class="cms-attachments__error" x-show="error !== ''" x-cloak x-text="error"></p>

    {{-- `accept` only narrows what the dialog of the system offers; what is
         kept is decided by the server, see MediaLibraryCommandsController. --}}
    <input
        type="file"
        class="cms-attachments__file"
        @if($accept !== '') accept="{{ $accept }}" @endif
        @if($multiple) multiple @endif
        x-ref="file"
        tabindex="-1"
        aria-hidden="true"
        x-on:change="picked($event)"
    >
</div>
{!! $modalMarkup !!}
