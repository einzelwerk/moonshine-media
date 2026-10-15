@props([
    /* The queue of the uploads, the dropzone over the whole screen and
       the two file inputs.

       A partial because the picker dialog uploads through the same
       queue: one path of upload and one set of words for its refusals.
       `accept` and `uploadHints` come from the server, everything else
       from the component it stands inside. */
    'accept' => '',
    'uploadHints' => [],
])
    <div class="cms-lib-queue" x-show="uploads.length > 0" x-cloak>
        <div class="cms-lib-queue__head">
            <span x-text="labels.uploader.title"></span>
            <span class="cms-lib-queue__progress" x-text="labels.uploader.progress.replace(':done', doneUploads()).replace(':total', uploads.length)"></span>
            <button type="button" class="cms-lib-queue__close" x-bind:aria-label="labels.uploader.hide" x-on:click="uploads = []">
                <x-moonshine::icon icon="x-mark" size="4" />
            </button>
        </div>

        <ul class="cms-lib-queue__list">
            <template x-for="job in uploads" x-bind:key="job.id">
                <li class="cms-lib-queue__item" x-bind:data-cms-job="job.id" x-bind:class="'_is-' + job.state">
                    <span class="cms-lib-queue__name" x-bind:title="job.name" x-text="shorten(job.name, 34)"></span>
                    <span class="cms-lib-queue__state" x-text="stateLabel(job)"></span>
                    <span class="cms-lib-queue__track"><i x-bind:style="'width: ' + job.progress + '%'"></i></span>
                    <span class="cms-lib-queue__message" x-show="job.message" x-text="job.message"></span>
                    <span class="cms-lib-queue__actions">
                        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-show="job.state === 'failed'" x-on:click="retry(job)" x-text="labels.uploader.retry"></button>
                        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-show="job.state === 'duplicate'" x-on:click="useExisting(job)" x-text="labels.uploader.use_existing"></button>
                        <button type="button" class="cms-lib-btn cms-lib-btn--ghost" x-show="job.state === 'duplicate'" x-on:click="uploadCopy(job)" x-text="labels.uploader.upload_copy"></button>
                    </span>
                </li>
            </template>
        </ul>
    </div>

    {{-- -------------------------------------------------------- dropzone --}}
    <div class="cms-lib-drop" x-show="dragging" x-cloak>
        <div class="cms-lib-drop__card">
            <x-moonshine::icon icon="arrow-up-tray" size="10" />
            <p class="cms-lib-drop__title" x-text="dropTitle()"></p>
            @foreach($uploadHints as $hint)
                <p class="cms-lib-drop__hint">{{ $hint }}</p>
            @endforeach
        </div>
    </div>

    {{-- ---------------------------------------------------------- inputs --}}
    {{-- Opened by «Загрузить» and by «Заменить файл», never reached on its
         own: it is out of the tab order and out of the reading order, and
         the buttons that open it carry the words. --}}
    <input type="file" class="cms-lib-file" x-ref="upload" multiple tabindex="-1" aria-hidden="true" accept="{{ $accept }}" x-on:change="picked($event)">
    <input type="file" class="cms-lib-file" x-ref="replace" tabindex="-1" aria-hidden="true" accept="{{ $accept }}" x-on:change="replacePicked($event)">
