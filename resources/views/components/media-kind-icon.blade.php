@props(['expr' => 'item.kind', 'size' => '8'])
{{-- The glyph of a kind that has no preview of its own. It is drawn by the
     icons of the panel and not by a string in the script: a client that
     builds markup out of text is exactly what this screen stays away from. --}}
<template x-if="{{ $expr }} === 'video'"><x-moonshine::icon icon="film" :size="$size" /></template>
<template x-if="{{ $expr }} === 'audio'"><x-moonshine::icon icon="musical-note" :size="$size" /></template>
<template x-if="{{ $expr }} === 'document'"><x-moonshine::icon icon="document-text" :size="$size" /></template>
<template x-if="{{ $expr }} === 'vector'"><x-moonshine::icon icon="paint-brush" :size="$size" /></template>
<template x-if="{{ $expr }} === 'image'"><x-moonshine::icon icon="photo" :size="$size" /></template>
