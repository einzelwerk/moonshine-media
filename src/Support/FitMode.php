<?php

declare(strict_types=1);

namespace Ewk\Cms\Media\Support;

/**
 * How a picture meets the box of a preset, named as CSS `object-fit` names
 * the same three answers.
 */
enum FitMode: string
{
    /** Fills the box and crops what sticks out. */
    case COVER = 'cover';

    /** The whole picture inside the box, the rest of the box filled. */
    case CONTAIN = 'contain';

    /**
     * The whole picture inside the box and nothing added around it, never
     * enlarged: a vertical frame stays vertical, a small one stays small.
     */
    case SCALE_DOWN = 'scale-down';
}
