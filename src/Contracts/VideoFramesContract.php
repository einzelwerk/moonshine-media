<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Contracts;

use RuntimeException;

/**
 * What the library learns about a video from the video itself: one frame, to
 * make the conversions of a picture out of, and the size it is shown at.
 *
 * Both need tools outside PHP, so an installation without them is a normal
 * state and not an error: `available()` says no, and a video stays a file
 * without a preview, as it was before frames were cut at all.
 */
interface VideoFramesContract
{
    /**
     * Whether frames are cut here: the installation turned it on and the
     * tools are where it said.
     */
    public function available(): bool;

    /**
     * One frame of the video, as the bytes of a PNG. The source is a local
     * path or the `http(s)` address of a file on a disk elsewhere.
     *
     * @throws RuntimeException when the tool refuses the file or runs out of time
     */
    public function frame(string $source): string;

    /**
     * Width and height the video is shown at, a rotation of the phone that
     * shot it applied; `null` when the file says nothing a reader can trust.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $path): ?array;
}
