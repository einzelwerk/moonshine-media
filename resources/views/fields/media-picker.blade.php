@props([
    'picker',
    'mediaId' => null,
    'previewUrl' => null,
    'previewName' => null,
    'previewSize' => null,
    'previewKind' => null,
    'missing' => false,
    'kinds' => [],
    'locales' => [],
    'defaultLocale' => null,
    'alt' => [],
    'fileAlt' => [],
    'describable' => true,
    'accept' => '',
    'modal' => 'cms-media-picker',
    'modalMarkup' => '',
    'endpoints' => [],
    'scope' => '',
])
@php
    /** @var \Ewk\Cms\Media\MoonShine\Fields\MediaPicker $picker */
    $labels = [
        'uploading' => (string) __('moonshine-media::media.uploading'),
        'missing' => (string) __('moonshine-media::media.missing'),
        'duplicate' => (string) __('moonshine-media::media.duplicate'),
        'failed' => (string) __('moonshine-media::library.messages.request_failed'),
    ];
    $altOpen = array_filter($alt, static fn (string $value): bool => $value !== '') !== [];
@endphp
<div
    class="cms-media"
    data-cms-media
    data-validation-wrapper
    data-validation-field="{{ $picker->getNameAttribute() }}"
    x-data="cmsMediaField({
        id: @js($mediaId),
        preview: @js($previewUrl),
        name: @js($previewName),
        size: @js($previewSize),
        kind: @js($previewKind),
        missing: @js((bool) $missing),
        kinds: @js(array_values($kinds)),
        modal: @js($modal),
        scope: @js($scope),
        endpoints: @js($endpoints),
        labels: @js($labels),
        altOpen: @js($altOpen),
        token: '{{ csrf_token() }}',
    })"
    x-on:cms-media-pick.window="chosen($event)"
    {{-- Whatever ends the dialog — a file taken, «Отмена», Esc — the keyboard
         comes back to the button that opened it and not to the top of the
         document. --}}
    x-on:cms-media-close.window="closed($event)"
>
    {{-- The one input the value of this field travels in. Inside a row of a
         repeater the panel rewrites its name on the client, and the inputs of
         the description are named after it, see {@see CompositeValueField}. --}}
    <input type="hidden" x-ref="idInput" value="{{ $mediaId }}" x-effect="$el.value = id" {{ $picker->inputAttributes('[id]') }}>

    {{-- The zone is the whole field: dropping a file on it is the same gesture
         as choosing one, and the picture that is already there is drawn inside
         it instead of next to it. --}}
    <div
        class="dropzone cms-media__zone"
        x-bind:class="{ 'cms-media__zone--over': over, 'cms-media__zone--empty': ! filled }"
        x-on:dragover.prevent="over = true"
        x-on:dragleave.prevent="over = false"
        x-on:drop.prevent="dropped($event)"
    >
        <div class="cms-media__body" x-show="filled" x-cloak>
            <div class="dropzone-item cms-media__thumb">
                <img x-bind:src="preview" x-show="preview" alt="" x-cloak>
                <span class="cms-media__glyph" x-show="! preview" x-cloak>{{ __('moonshine-media::media.no_preview') }}</span>
            </div>

            <div class="cms-media__meta">
                <span class="dropzone-file-name cms-media__name" x-text="name"></span>
                <span class="cms-media__size" x-text="size"></span>
                <span class="cms-media__note" x-show="note() !== ''" x-cloak x-text="note()"></span>
            </div>

            {{-- The same two buttons the empty field offers, named by where
                 the picture comes from: «Заменить» beside «Загрузить» read as
                 one action twice, because both of them replace the picture. --}}
            <div class="cms-media__actions">
                <button type="button" class="btn btn-secondary" data-cms-media-open x-bind:disabled="busy" x-on:click="openLibrary()">
                    {{ __('moonshine-media::media.choose') }}
                </button>
                <button type="button" class="btn btn-secondary" x-bind:disabled="busy" x-on:click="upload()">
                    {{ __('moonshine-media::media.upload') }}
                </button>
                <button type="button" class="btn btn-secondary cms-media__clear" x-on:click="clear()">
                    {{ __('moonshine-media::media.clear') }}
                </button>
            </div>
        </div>

        <div class="cms-media__empty" x-show="! filled" x-cloak>
            <span class="cms-media__hint" x-text="over ? @js((string) __('moonshine-media::media.drop')) : @js((string) __('moonshine-media::media.empty'))"></span>
            <span class="cms-media__actions">
                <button type="button" class="btn btn-secondary" data-cms-media-open x-bind:disabled="busy" x-on:click="openLibrary()">
                    {{ __('moonshine-media::media.choose') }}
                </button>
                <button type="button" class="btn btn-secondary" x-bind:disabled="busy" x-on:click="upload()">
                    {{ __('moonshine-media::media.upload') }}
                </button>
            </span>
        </div>
    </div>

    {{-- How far the file has gone up. The bar and not a spinner: a picture of
         eight megabytes on a hotel connection is a minute of waiting, and a
         spinner says nothing about whether it is working. --}}
    <div class="cms-media__progress" x-show="busy" x-cloak>
        <i x-bind:style="'width: ' + progress + '%'"></i>
    </div>

    {{-- The same question the library asks: the bytes are already stored, so
         either that record is what they meant or they want a second copy. --}}
    <div class="cms-media__duplicate" x-show="duplicate" x-cloak>
        <span x-text="labels.duplicate"></span>
        <button type="button" class="btn btn-secondary" x-on:click="useExisting()">
            {{ __('moonshine-media::media.use_existing') }}
        </button>
        <button type="button" class="btn btn-secondary" x-on:click="uploadCopy()">
            {{ __('moonshine-media::media.upload_copy') }}
        </button>
    </div>

    <p class="cms-media__error" x-show="error !== ''" x-cloak x-text="error"></p>

    {{-- ------------------------------------------------------ description --}}
    @if($describable && $locales !== [])
        <div class="cms-media__alt" x-show="filled" x-cloak>
            <button type="button" class="cms-media__collapse" x-bind:aria-expanded="altOpen" x-on:click="altOpen = ! altOpen">
                <span x-bind:class="altOpen && '_is-open'"><x-moonshine::icon icon="chevron-right" size="4" /></span>
                {{ __('moonshine-media::media.alt') }}
            </button>

            <div class="cms-media__alt-body" x-show="altOpen" x-cloak>
                @foreach($locales as $locale)
                    <label
                        class="cms-media__alt-row"
                        data-cms-alt-locale="{{ $locale }}"
                        {{-- One language switch drives the whole form when
                             the form has one, and a form without that switch
                             shows every language at once, which is what the
                             badge beside each input is for.

                             The mark is its own and not `data-cms-locale`: an
                             empty override here means «take the description of
                             the file» and is not a translation anybody forgot,
                             so the editor must not badge it as one. --}}
                        x-show="typeof locale === typeof undefined ? true : locale === $el.dataset.cmsAltLocale"
                    >
                        <span class="cms-media__alt-locale">{{ mb_strtoupper($locale) }}</span>
                        <input
                            type="text"
                            class="form-input"
                            value="{{ $alt[$locale] ?? '' }}"
                            placeholder="{{ $fileAlt[$locale] ?? '' }}"
                            {{ $picker->inputAttributes('[metadata][alt]['.$locale.']') }}
                        >
                    </label>
                @endforeach

                <p class="cms-media__alt-hint">{{ __('moonshine-media::media.alt_hint') }}</p>
            </div>
        </div>
    @endif

    {{-- `accept` only narrows what the dialog of the system offers; what is
         kept is decided by the server, see MediaLibraryCommandsController. --}}
    <input
        type="file"
        class="cms-media__file"
        @if($accept !== '') accept="{{ $accept }}" @endif
        x-ref="file"
        tabindex="-1"
        aria-hidden="true"
        x-on:change="picked($event)"
    >
</div>
{!! $modalMarkup !!}
