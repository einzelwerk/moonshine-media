<?php

declare(strict_types=1);

return [
    /*
     * Which conversion of `cms-media.presets` the panel draws as a thumbnail:
     * the tiles of the library, the card of a media field and the column of
     * a listing.
     *
     * The default is `thumb`. An installation whose `thumb` is shaped for the
     * frontend — padded, or of a proportion of its own — declares a square
     * preset next to it and names it here, so that the grid of the panel
     * stays a wall of equal tiles without the public payload changing.
     *
     * A record uploaded before the preset was declared has no such variant
     * and falls back to the nearest ready one, so nothing shows a broken
     * picture while the conversions are being generated.
     */
    'thumbnail_preset' => 'thumb',

    /*
     * The zone the panel shows instants in, such as when a file was
     * uploaded. The database keeps them in the zone of the application; an
     * installation whose editors live elsewhere names their zone here. `null`
     * is the zone of the application (`app.timezone`).
     *
     * Wall-clock values stored as content (a date and a time somebody typed)
     * are not instants and stay as they were typed.
     */
    'display_timezone' => null,
];
