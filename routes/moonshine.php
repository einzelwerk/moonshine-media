<?php

declare(strict_types=1);

use Ewk\Cms\Media\MoonShine\Http\Controllers\MediaLibraryCommandsController;
use Ewk\Cms\Media\MoonShine\Http\Controllers\MediaLibraryController;
use Illuminate\Support\Facades\Route;

/*
 * The media library. The group carries the prefix, the domain and the
 * middleware of the panel, plus its authentication: the answers name files of
 * the installation and are meant for an administrator.
 *
 * Reading and writing are two controllers on purpose. Everything that writes
 * takes a POST and nothing else, so the CSRF middleware of the panel stands
 * in front of every one of them and no address an editor opens can change a
 * file. The upload is the one route with a rate limit of its own: it is the
 * only one that writes bytes to a storage.
 */

Route::controller(MediaLibraryController::class)
    ->prefix('cms/media')
    ->as('cms.media.')
    ->group(static function (): void {
        Route::get('items', 'items')->name('items');
        Route::get('folders', 'folders')->name('folders');
        // The body of the picker dialog: html and not json, because it is the
        // grid and the tree of the section drawn by the same partials.
        Route::get('picker', 'picker')->name('picker');
        // Declared last of the reads: «items», «folders» and «picker» are
        // addresses and not identifiers, and the router takes the first route
        // that matches.
        Route::get('{uuid}', 'show')->name('show');
    });

Route::controller(MediaLibraryCommandsController::class)
    ->prefix('cms/media')
    ->as('cms.media.')
    ->group(static function (): void {
        Route::post('upload', 'upload')->middleware('throttle:60,1')->name('upload');
        Route::post('move', 'move')->name('move');
        Route::post('delete', 'delete')->name('delete');

        Route::post('folders', 'createFolder')->name('folders.create');
        Route::post('folders/{folder}', 'renameFolder')->name('folders.rename');
        Route::post('folders/{folder}/move', 'moveFolder')->name('folders.move');
        Route::post('folders/{folder}/delete', 'deleteFolder')->name('folders.delete');

        // Throttled like the upload: it is the other route that writes
        // bytes to a storage.
        Route::post('{uuid}/replace', 'replace')->middleware('throttle:60,1')->name('replace');
        Route::post('{uuid}', 'update')->name('update');
    });
