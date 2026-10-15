@props([
    'thumbnail' => null,
    'title' => '',
])
{{-- The picture of a record beside its name, in one cell: a wide table loses
     a column instead of scrolling sideways. --}}
<div class="cms-attachment-preview">
    @if($thumbnail)
        <img class="cms-attachment-preview__thumb" src="{{ $thumbnail }}" alt="" loading="lazy">
    @else
        <span class="cms-attachment-preview__thumb cms-attachment-preview__thumb--empty" aria-hidden="true">
            <x-moonshine::icon icon="photo" size="4" />
        </span>
    @endif

    <span class="cms-attachment-preview__text">{{ $title }}</span>
</div>
